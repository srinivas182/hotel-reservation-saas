<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests\Http\Controllers;

use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\BookingRequests\Exceptions\RequestNotPossible;
use App\Domains\Rates\Quote\StayRequest;
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

final class BookingRequestController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BookingRequestService $requests,
    ) {}

    public function approve(Request $request, BookingRequest $bookingRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $override = $request->filled('override_total');

        if ($override && ! $user->can('bookings.override_price')) {
            abort(403);
        }

        $data = $request->validate([
            'edit' => ['required', 'boolean'],
            'acknowledge_price' => ['required', 'boolean'],
            'room_category_id' => ['required_if:edit,true', 'nullable', 'string', Rule::exists('room_categories', 'id')->where('hotel_id', $this->context->id())],
            'check_in' => ['required_if:edit,true', 'nullable', 'date_format:Y-m-d'],
            'check_out' => ['required_if:edit,true', 'nullable', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required_if:edit,true', 'nullable', 'integer', 'min:1', 'max:40'],
            'children' => ['nullable', 'string', 'max:100'],
            'rooms' => ['required_if:edit,true', 'nullable', 'integer', 'min:1', 'max:20'],
            'breakfast' => ['nullable', 'boolean'],
            'extra_bed' => ['nullable', 'boolean'],
            'override_total' => [$override ? 'required' : 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'override_reason' => [$override ? 'required' : 'nullable', 'string', 'max:300'],
        ], ['override_reason.required' => __('Give a reason for changing the price.')]);

        $changed = null;

        if ((bool) $data['edit']) {
            $ages = array_values(array_filter(array_map('trim', explode(',', (string) ($data['children'] ?? ''))), fn (string $a): bool => $a !== ''));
            $changed = new StayRequest(
                categoryId: (string) $data['room_category_id'],
                checkIn: CarbonImmutable::parse((string) $data['check_in']),
                checkOut: CarbonImmutable::parse((string) $data['check_out']),
                adults: (int) $data['adults'],
                childAges: array_map(fn (string $a): int => max(0, min(17, (int) $a)), $ages),
                rooms: (int) $data['rooms'],
                breakfast: (bool) ($data['breakfast'] ?? false),
                extraBed: (bool) ($data['extra_bed'] ?? false),
            );
        }

        try {
            $booking = $this->requests->approve(
                $bookingRequest,
                $user,
                $changed,
                (bool) $data['acknowledge_price'] || $override,
                $override ? Money::of((string) $data['override_total'], $this->context->hotel()->currency, roundingMode: RoundingMode::HalfUp) : null,
                $data['override_reason'] ?? null,
            );
        } catch (RequestNotPossible $exception) {
            throw ValidationException::withMessages([$exception->reason === 'price_changed' ? 'acknowledge_price' : 'request' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Approved. Booking :reference created.', ['reference' => $booking->reference])]);

        return back();
    }

    public function decline(Request $request, BookingRequest $bookingRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(['full', 'dates', 'other'])],
            'note' => ['required_if:reason,other', 'nullable', 'string', 'max:250'],
        ]);

        $reason = match ($data['reason']) {
            'full' => 'full',
            'dates' => 'dates',
            default => 'other: '.trim((string) $data['note']),
        };

        try {
            $this->requests->decline($bookingRequest, $user, $reason);
        } catch (RequestNotPossible $exception) {
            throw ValidationException::withMessages(['request' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request declined.')]);

        return back();
    }
}
