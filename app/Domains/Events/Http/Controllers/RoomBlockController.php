<?php

declare(strict_types=1);

namespace App\Domains\Events\Http\Controllers;

use App\Domains\Bookings\Exceptions\BookingNotPossible;
use App\Domains\Events\Exceptions\EventNotPossible;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\RoomBlock;
use App\Domains\Events\RoomBlocks;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class RoomBlockController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RoomBlocks $blocks,
    ) {}

    public function store(Request $request, Event $event, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'room_category_id' => ['required', 'string', Rule::exists('room_categories', 'id')->where('hotel_id', $this->context->id())],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'rooms' => ['required', 'integer', 'min:1', 'max:500'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'cutoff_date' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $this->blocks->create(
                $event,
                RoomCategory::query()->findOrFail((string) $data['room_category_id']),
                CarbonImmutable::parse((string) $data['check_in']),
                CarbonImmutable::parse((string) $data['check_out']),
                (int) $data['rooms'],
                Money::of((string) $data['rate'], $this->context->hotel()->currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt(),
                CarbonImmutable::parse((string) $data['cutoff_date']),
                $user,
            );
        } catch (EventNotPossible $e) {
            throw ValidationException::withMessages(['rooms' => $e->getMessage()]);
        }

        return back();
    }

    public function book(Request $request, RoomBlock $block, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:25'],
            'email' => ['nullable', 'email', 'max:191'],
            'adults' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $booking = $this->blocks->book($block, [
                'first_name' => (string) $data['first_name'], 'last_name' => $data['last_name'] ?? null, 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null,
            ], (int) $data['adults'], $user);
        } catch (EventNotPossible|BookingNotPossible $e) {
            throw ValidationException::withMessages(['block' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booked: :reference.', ['reference' => $booking->reference])]);

        return back();
    }

    public function release(RoomBlock $block, #[CurrentUser] User $user): RedirectResponse
    {
        $this->blocks->release($block, $user);

        return back();
    }
}
