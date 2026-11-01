<?php

declare(strict_types=1);

namespace App\Domains\Availability\Http\Controllers;

use App\Domains\Availability\Exceptions\NotEnoughRooms;
use App\Domains\Availability\HoldService;
use App\Domains\Availability\InventoryControls;
use App\Domains\Availability\Models\InventoryHold;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Calendar actions: stop-sell, rooms held back, and manual holds.
 */
final class InventoryController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function stopSell(Request $request, InventoryControls $controls, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $this->rangeInput($request, ['stop_sell' => ['required', 'boolean']]);

        $stopSell = (bool) ($data['stop_sell'] ?? false);
        $controls->setStopSell($data['categories'], $data['from'], $data['to'], $stopSell, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $stopSell ? __('Sales stopped for these nights.') : __('Sales reopened for these nights.')]);

        return back();
    }

    public function holdBack(Request $request, InventoryControls $controls, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $this->rangeInput($request, ['rooms' => ['required', 'integer', 'min:0', 'max:500']]);

        $controls->setHeldBack($data['categories'], $data['from'], $data['to'], (int) ($data['rooms'] ?? 0), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rooms held back updated.')]);

        return back();
    }

    public function placeHold(Request $request, HoldService $holds, #[CurrentUser] User $user): RedirectResponse
    {
        $hotel = $this->context->hotel();

        $data = $request->validate([
            'room_category_id' => ['required', 'string', Rule::exists('room_categories', 'id')->where('hotel_id', $hotel->getKey())],
            'check_in' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::now($hotel->timezone)->toDateString()],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'rooms' => ['required', 'integer', 'min:1', 'max:50'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $category = RoomCategory::query()->whereKey((string) $data['room_category_id'])->firstOrFail();

        try {
            $holds->place(
                $category,
                CarbonImmutable::parse((string) $data['check_in']),
                CarbonImmutable::parse((string) $data['check_out']),
                (int) $data['rooms'],
                by: $user,
                note: $data['note'] ?? null,
                minutes: isset($data['minutes']) ? (int) $data['minutes'] : null,
            );
        } catch (NotEnoughRooms $exception) {
            throw ValidationException::withMessages(['rooms' => __('Only :count room(s) free on :date.', ['count' => $exception->available, 'date' => $exception->date])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rooms held.')]);

        return back();
    }

    public function extendHold(InventoryHold $inventoryHold, HoldService $holds): RedirectResponse
    {
        try {
            $holds->extend($inventoryHold);
        } catch (NotEnoughRooms) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The rooms are no longer free.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Hold extended.')]);

        return back();
    }

    public function releaseHold(InventoryHold $inventoryHold, HoldService $holds, #[CurrentUser] User $user): RedirectResponse
    {
        $holds->release($inventoryHold, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Held rooms released.')]);

        return back();
    }

    /**
     * @param  array<string, list<string>>  $extra
     * @return array{categories: list<string>, from: CarbonImmutable, to: CarbonImmutable, stop_sell?: bool, rooms?: int}
     */
    private function rangeInput(Request $request, array $extra): array
    {
        $hotel = $this->context->hotel();

        $data = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['string', Rule::exists('room_categories', 'id')->where('hotel_id', $hotel->getKey())],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before:'.CarbonImmutable::parse((string) $request->input('from', 'now'))->addDays(366)->toDateString()],
            ...$extra,
        ]);

        /** @var array{categories: list<string>, from: CarbonImmutable, to: CarbonImmutable, stop_sell?: bool, rooms?: int} */
        return [
            ...$data,
            'categories' => array_values(array_unique(array_map('strval', $data['category_ids']))),
            'from' => CarbonImmutable::parse((string) $data['from']),
            'to' => CarbonImmutable::parse((string) $data['to']),
        ];
    }
}
