<?php

declare(strict_types=1);

namespace App\Domains\Rates\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Rates\BreakfastMode;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class CategoryRateController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function update(Request $request, RoomCategory $roomCategory): RedirectResponse
    {
        $hotel = $this->context->hotel();
        $amount = ['required', 'numeric', 'min:0', 'max:1000000000'];

        $data = $request->validate([
            'base' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'extra_adult' => $amount,
            'child' => $amount,
            'extra_bed' => $amount,
            'breakfast_mode' => ['required', Rule::enum(BreakfastMode::class)],
            'breakfast' => [$request->input('breakfast_mode') === BreakfastMode::Optional->value ? 'required' : 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'min_nights' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        $minor = fn (mixed $value): int => Money::of((string) ($value ?? 0), $hotel->currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt();

        $rate = CategoryRate::query()->firstOrNew(['room_category_id' => $roomCategory->id]);
        $before = $rate->exists ? Arr::except($rate->attributesToArray(), ['created_at', 'updated_at']) : null;

        $rate->fill([
            'base_minor' => $minor($data['base']),
            'extra_adult_minor' => $minor($data['extra_adult']),
            'child_minor' => $minor($data['child']),
            'extra_bed_minor' => $minor($data['extra_bed']),
            'breakfast_mode' => $data['breakfast_mode'],
            'breakfast_minor' => $data['breakfast_mode'] === BreakfastMode::Optional->value ? $minor($data['breakfast']) : 0,
            'min_nights' => (int) $data['min_nights'],
        ])->save();

        Audit::record('rates.updated', 'Room rate updated', subject: $hotel, properties: [
            'category' => $roomCategory->code,
            'old' => $before,
            'new' => Arr::except($rate->attributesToArray(), ['created_at', 'updated_at']),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rate saved.')]);

        return back();
    }
}
