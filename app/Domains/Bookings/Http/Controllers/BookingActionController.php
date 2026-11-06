<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Exceptions\BookingActionNotAllowed;
use App\Domains\Bookings\Exceptions\BookingNotPossible;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\Folio;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\Room;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Booking actions from the booking page and the Today screen.
 */
final class BookingActionController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BookingLifecycle $lifecycle,
    ) {}

    public function confirm(Booking $booking, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->attempt(fn () => $this->lifecycle->confirm($booking, $user), __('Booking confirmed.'));
    }

    public function cancel(Request $request, Booking $booking, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        return $this->attempt(fn () => $this->lifecycle->cancel($booking, (string) $data['reason'], $user), __('Booking cancelled.'));
    }

    public function noShow(Booking $booking, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->attempt(fn () => $this->lifecycle->noShow($booking, $user), __('Marked as no-show.'));
    }

    public function update(Request $request, Booking $booking, #[CurrentUser] User $user): RedirectResponse
    {
        $hotel = $this->context->hotel();
        $override = $request->filled('override_total');

        if ($override && ! $user->can('bookings.override_price')) {
            abort(403);
        }

        $request->merge(['children_ages' => array_values(array_filter(
            array_map('trim', explode(',', (string) $request->input('children', ''))),
            fn (string $age): bool => $age !== '',
        ))]);

        $data = $request->validate([
            'room_category_id' => ['required', 'string', Rule::exists('room_categories', 'id')->where('hotel_id', $hotel->getKey())],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:40'],
            'children_ages' => ['array', 'max:20'],
            'children_ages.*' => ['integer', 'between:0,17'],
            'rooms' => ['required', 'integer', 'min:1', 'max:20'],
            'breakfast' => ['required', 'boolean'],
            'extra_bed' => ['required', 'boolean'],
            'override_total' => [$override ? 'required' : 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'override_reason' => [$override ? 'required' : 'nullable', 'string', 'max:300'],
        ], ['override_reason.required' => __('Give a reason for changing the price.')]);

        $stay = new StayRequest(
            categoryId: (string) $data['room_category_id'],
            checkIn: CarbonImmutable::parse((string) $data['check_in']),
            checkOut: CarbonImmutable::parse((string) $data['check_out']),
            adults: (int) $data['adults'],
            childAges: array_values(array_map('intval', (array) ($data['children_ages'] ?? []))),
            rooms: (int) $data['rooms'],
            breakfast: (bool) $data['breakfast'],
            extraBed: (bool) $data['extra_bed'],
        );

        try {
            $this->lifecycle->modify(
                $booking,
                $stay,
                $user,
                $override ? Money::of((string) $data['override_total'], $hotel->currency, roundingMode: RoundingMode::HalfUp) : null,
                $data['override_reason'] ?? null,
            );
        } catch (BookingNotPossible $exception) {
            throw ValidationException::withMessages(['room_category_id' => $exception->getMessage()]);
        } catch (BookingActionNotAllowed $exception) {
            throw ValidationException::withMessages(['room_category_id' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booking changed.')]);

        return back();
    }

    public function assignRoom(Request $request, Booking $booking, int $position, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['room_id' => ['required', 'string']]);
        $room = Room::query()->whereKey((string) $data['room_id'])->first();

        if ($room === null) {
            throw ValidationException::withMessages(['room_id' => __('Unknown room.')]);
        }

        return $this->attempt(fn () => $this->lifecycle->assignRoom($booking, $position, $room, $user), __('Room number saved.'));
    }

    public function checkIn(Request $request, Booking $booking, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'rooms' => ['nullable', 'array'],
            'rooms.*' => ['nullable', 'string'],
        ]);

        $rooms = array_filter((array) ($data['rooms'] ?? []), fn ($id): bool => is_string($id) && $id !== '');

        return $this->attempt(fn () => $this->lifecycle->checkIn($booking, $rooms, $user), __('Guest checked in.'));
    }

    public function checkOut(Request $request, Booking $booking, Folio $folio, #[CurrentUser] User $user): RedirectResponse
    {
        $reason = trim((string) $request->input('balance_reason', ''));
        $balance = $folio->balance($booking);

        if ($balance !== 0 && $reason === '') {
            $amount = MoneyFormatter::format(Money::ofMinor($balance, $booking->currency ?? $this->context->hotel()->currency), $this->context->hotel()->default_locale);

            throw ValidationException::withMessages(['balance' => __('The room bill shows :amount still due. Record the payment, or give a reason to check out anyway.', ['amount' => $amount])]);
        }

        $response = $this->attempt(fn () => $this->lifecycle->checkOut($booking, $user), __('Guest checked out.'));

        if ($balance !== 0 && $booking->fresh()?->status === BookingStatus::CheckedOut) {
            Audit::record('bookings.checked_out_with_balance', 'Checked out with a balance on the room bill', subject: $booking, causer: $user, properties: ['balance_minor' => $balance, 'reason' => mb_substr($reason, 0, 300)]);
        }

        return $response;
    }

    private function attempt(Closure $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (BookingActionNotAllowed $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $success]);

        return back();
    }
}
