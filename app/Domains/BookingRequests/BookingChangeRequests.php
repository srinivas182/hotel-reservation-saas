<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Jobs\TellGuestAboutBooking;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\StayChanges;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Rates\Quote\StayRequest;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 0.39.3: a guest's change or cancellation of a booking, waiting for
 * reception. One open request per booking: a newer one replaces it.
 * Applying makes the change (or cancels) and tells the guest; declining
 * tells the guest why.
 */
final class BookingChangeRequests
{
    public function __construct(
        private readonly BookingLifecycle $lifecycle,
        private readonly StayChanges $stays,
    ) {}

    public function requestChange(Booking $booking, ?Conversation $conversation, StayRequest $stay, Money $newTotal, string $summary): BookingChangeRequest
    {
        return $this->open($booking, $conversation, BookingChangeRequest::CHANGE, [
            'category_id' => $stay->categoryId,
            'check_in' => $stay->checkIn->toDateString(),
            'check_out' => $stay->checkOut->toDateString(),
            'adults' => $stay->adults,
            'children_ages' => $stay->childAges,
            'rooms' => $stay->rooms,
            'breakfast' => $stay->breakfast,
            'extra_bed' => $stay->extraBed,
        ], $newTotal->getMinorAmount()->toInt(), $summary);
    }

    public function requestCancellation(Booking $booking, ?Conversation $conversation, string $summary): BookingChangeRequest
    {
        return $this->open($booking, $conversation, BookingChangeRequest::CANCELLATION, null, null, $summary);
    }

    /**
     * @return Collection<int, BookingChangeRequest>
     */
    public function pending(): Collection
    {
        return BookingChangeRequest::query()->with(['booking.guest', 'booking.category'])
            ->where('status', 'pending')->oldest('created_at')->get();
    }

    public function apply(BookingChangeRequest $request, User $by): void
    {
        $this->assertPending($request);
        $booking = $request->booking;

        DB::transaction(function () use ($request, $booking, $by): void {
            if ($request->kind === BookingChangeRequest::CANCELLATION) {
                $this->lifecycle->cancel($booking, (string) __('Guest asked on WhatsApp: :summary', ['summary' => $request->summary]), $by);
            } else {
                $stay = $request->newStay();
                if ($stay === null) {
                    throw new InvalidArgumentException('A change request without a stay.');
                }
                // 0.40.0: a guest already in the hotel only changes the departure.
                $booking->status === BookingStatus::CheckedIn
                    ? $this->stays->change($booking, $stay->checkOut, $by)
                    : $this->lifecycle->modify($booking, $stay, $by);
            }

            $request->forceFill(['status' => 'applied', 'decided_by' => $by->getKey(), 'decided_at' => now()])->save();
        });

        Audit::record('booking_changes.applied', $request->kind === BookingChangeRequest::CANCELLATION ? 'Guest cancellation request applied' : 'Guest change request applied', subject: $booking, causer: $by, properties: ['request' => $request->id]);

        TellGuestAboutBooking::dispatch($booking->id, $request->kind === BookingChangeRequest::CANCELLATION ? TellGuestAboutBooking::CANCELLED : TellGuestAboutBooking::CHANGED);
    }

    public function decline(BookingChangeRequest $request, User $by, string $reason): void
    {
        $this->assertPending($request);

        $request->forceFill(['status' => 'declined', 'decided_by' => $by->getKey(), 'decided_at' => now(), 'decline_reason' => mb_substr($reason, 0, 300)])->save();

        Audit::record('booking_changes.declined', 'Guest change or cancellation request declined', subject: $request->booking, causer: $by, properties: ['request' => $request->id, 'reason' => $reason]);

        TellGuestAboutBooking::dispatch($request->booking_id, TellGuestAboutBooking::DECLINED, $request->kind, $reason);
    }

    /**
     * @param  array<string, mixed>|null  $stay
     */
    private function open(Booking $booking, ?Conversation $conversation, string $kind, ?array $stay, ?int $newTotal, string $summary): BookingChangeRequest
    {
        return DB::transaction(function () use ($booking, $conversation, $kind, $stay, $newTotal, $summary): BookingChangeRequest {
            BookingChangeRequest::query()->where('booking_id', $booking->id)->where('status', 'pending')->lockForUpdate()
                ->update(['status' => 'withdrawn', 'decided_at' => now()]);

            $request = BookingChangeRequest::query()->create([
                'booking_id' => $booking->id,
                'conversation_id' => $conversation?->id,
                'kind' => $kind,
                'status' => 'pending',
                'stay' => $stay,
                'current_total_minor' => $booking->total_minor,
                'new_total_minor' => $newTotal,
                'currency' => $booking->currency,
                'summary' => mb_substr($summary, 0, 300),
            ]);

            Audit::record('booking_changes.requested', $kind === BookingChangeRequest::CANCELLATION ? 'Guest asked to cancel on WhatsApp' : 'Guest asked to change the booking on WhatsApp', subject: $booking, properties: ['request' => $request->id, 'summary' => $summary]);

            return $request;
        });
    }

    private function assertPending(BookingChangeRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException((string) __('This request was already handled.'));
        }
    }
}
