<?php

declare(strict_types=1);

namespace App\Domains\Rates\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Rates\Models\RateOverride;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class RateOverrideController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request): RedirectResponse
    {
        $override = RateOverride::query()->create($this->validated($request));

        Audit::record('rates.override_created', 'Rate override created', subject: $this->context->hotel(), properties: $this->auditData($override));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Override saved.')]);

        return back();
    }

    public function update(Request $request, RateOverride $rateOverride): RedirectResponse
    {
        $before = $this->auditData($rateOverride);
        $rateOverride->update($this->validated($request));

        Audit::record('rates.override_updated', 'Rate override updated', subject: $this->context->hotel(), properties: [
            'old' => $before,
            'new' => $this->auditData($rateOverride),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Override saved.')]);

        return back();
    }

    public function destroy(RateOverride $rateOverride): RedirectResponse
    {
        $data = $this->auditData($rateOverride);
        $rateOverride->delete();

        Audit::record('rates.override_deleted', 'Rate override deleted', subject: $this->context->hotel(), properties: $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Override deleted.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $hotel = $this->context->hotel();

        $data = $request->validate([
            'room_category_id' => ['nullable', 'string', Rule::exists('room_categories', 'id')->where('hotel_id', $hotel->getKey())],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before:'.now()->addYears(3)->toDateString()],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'min_nights' => ['nullable', 'integer', 'min:1', 'max:30'],
            'stop_sell' => ['required', 'boolean'],
            'closed_to_arrival' => ['required', 'boolean'],
            'label' => ['required', 'string', 'max:100'],
        ]);

        if (($data['rate'] ?? null) === null && ($data['min_nights'] ?? null) === null && ! $data['stop_sell'] && ! $data['closed_to_arrival']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'rate' => __('Set a price, a minimum stay, stop-sell or closed to arrival.'),
            ]);
        }

        $weekdays = array_values(array_unique(array_map('intval', $data['weekdays'] ?? [])));
        sort($weekdays);

        return [
            'room_category_id' => $data['room_category_id'] ?? null,
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'weekdays' => $weekdays === [] || count($weekdays) === 7 ? null : $weekdays,
            'rate_minor' => ($data['rate'] ?? null) === null
                ? null
                : Money::of((string) $data['rate'], $hotel->currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt(),
            'min_nights' => $data['min_nights'] ?? null,
            'stop_sell' => (bool) $data['stop_sell'],
            'closed_to_arrival' => (bool) $data['closed_to_arrival'],
            'label' => trim((string) $data['label']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditData(RateOverride $override): array
    {
        return Arr::except($override->attributesToArray(), ['hotel_id', 'created_at', 'updated_at']);
    }
}
