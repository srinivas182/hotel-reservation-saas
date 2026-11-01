<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

use App\Domains\Audit\Audit;
use App\Domains\Availability\Exceptions\NotEnoughRooms;
use App\Domains\Availability\HoldService;
use App\Domains\Availability\HoldStatus;
use App\Domains\Availability\Models\InventoryHold;
use App\Domains\Bookings\Exceptions\BookingActionNotAllowed;
use App\Domains\Bookings\Exceptions\BookingNotPossible;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Rates\Quote\QuoteIssue;
use App\Domains\Rates\Quote\QuoteService;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happens to a booking after it is created. Each action
 * checks the booking's status first, and every change to availability runs
 * in one locked transaction with the status change, so the calendar,
 * quotes and WhatsApp always see the truth.
 */
final class BookingLifecycle
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly HoldService $holds,
        private readonly QuoteService $quotes,
        private readonly RoomAssignments $assignments,
    ) {}

    public function confirm(Booking $booking, ?User $by = null): void
    {
        $this->requireStatus($booking, [BookingStatus::Tentative], __('Only tentative bookings can be confirmed.'));

        try {
            DB::transaction(function () use ($booking, $by): void {
                $this->holds->convert($this->hold($booking));

                $booking->forceFill([
                    'status' => BookingStatus::Confirmed,
                    'tentative_until' => null,
                    'confirmed_at' => now(),
                    'confirmed_by' => $by?->getKey(),
                ])->save();
            });
        } catch (NotEnoughRooms) {
            throw new BookingActionNotAllowed(__('The hold has ended and the rooms are no longer free.'));
        }

        $this->audit('bookings.confirmed', 'Booking confirmed', $booking, $by);
    }

    public function cancel(Booking $booking, string $reason, ?User $by = null, bool $expired = false): void
    {
        $this->requireStatus($booking, [BookingStatus::Tentative, BookingStatus::Confirmed], __('Only tentative or confirmed bookings can be cancelled.'));

        if (trim($reason) === '') {
            throw new BookingActionNotAllowed(__('Give a reason for cancelling.'));
        }

        DB::transaction(function () use ($booking, $reason, $by): void {
            $this->freeNights($booking, $booking->check_in, $booking->check_out);

            $booking->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by?->getKey(),
                'cancel_reason' => trim($reason),
                'tentative_until' => null,
            ])->save();
        });

        $this->audit($expired ? 'bookings.expired' : 'bookings.cancelled', $expired ? 'Tentative booking expired' : 'Booking cancelled', $booking, $by, ['reason' => trim($reason)]);
    }

    public function noShow(Booking $booking, ?User $by = null): void
    {
        $this->requireStatus($booking, [BookingStatus::Confirmed], __('Only confirmed bookings can be marked as no-show.'));

        if ($booking->check_in->gt($this->today())) {
            throw new BookingActionNotAllowed(__('A guest can only be marked as no-show from the arrival date.'));
        }

        DB::transaction(function () use ($booking, $by): void {
            $this->freeNights($booking, $booking->check_in, $booking->check_out);

            $booking->forceFill([
                'status' => BookingStatus::NoShow,
                'no_show_at' => now(),
                'no_show_by' => $by?->getKey(),
            ])->save();
        });

        $this->audit('bookings.no_show', 'Marked as no-show', $booking, $by);
    }

    /**
     * Change the stay. The new stay is re-quoted and its rooms reserved in
     * the same transaction that gives back the old nights; if the new rooms
     * are not free, nothing changes. A changed price must be given again.
     */
    public function modify(Booking $booking, StayRequest $stay, ?User $by = null, ?Money $overrideTotal = null, ?string $overrideReason = null): void
    {
        $this->requireStatus($booking, [BookingStatus::Tentative, BookingStatus::Confirmed], __('Only tentative or confirmed bookings can be changed.'));

        if ($overrideTotal !== null && trim((string) $overrideReason) === '') {
            throw new BookingActionNotAllowed(__('Give a reason for changing the price.'));
        }

        // Availability is checked under lock below, without this booking's own nights.
        $quote = $this->quotes->quote($stay);
        $issues = array_values(array_filter($quote->issues, fn (QuoteIssue $issue): bool => $issue->code !== 'not_available'));

        if ($issues !== []) {
            throw new BookingNotPossible($issues);
        }

        $before = $this->stayData($booking);
        $category = RoomCategory::query()->findOrFail($stay->categoryId);
        $tentative = $booking->status === BookingStatus::Tentative;

        try {
            DB::transaction(function () use ($booking, $stay, $quote, $category, $tentative, $by, $overrideTotal, $overrideReason): void {
                $this->freeNights($booking, $booking->check_in, $booking->check_out);

                $minutes = $tentative && $booking->tentative_until !== null
                    ? max(1, (int) ceil(now()->diffInMinutes($booking->tentative_until)))
                    : 10;

                $hold = $this->holds->place($category, $stay->checkIn, $stay->checkOut, $stay->rooms, sourceType: 'booking', sourceId: $booking->id, by: $by, minutes: $minutes);

                if (! $tentative) {
                    $this->holds->convert($hold);
                }

                $categoryChanged = $booking->room_category_id !== $category->id;
                $total = $overrideTotal ?? $quote->total;

                $booking->forceFill([
                    'room_category_id' => $category->id,
                    'check_in' => $stay->checkIn,
                    'check_out' => $stay->checkOut,
                    'rooms' => $stay->rooms,
                    'adults' => $stay->adults,
                    'children_ages' => $stay->childAges,
                    'breakfast' => $stay->breakfast,
                    'extra_bed' => $stay->extraBed,
                    'price_snapshot' => BookingService::snapshot($quote),
                    'total_minor' => $total->getMinorAmount()->toInt(),
                    'price_overridden' => $overrideTotal !== null,
                    'override_reason' => $overrideTotal !== null ? trim((string) $overrideReason) : null,
                    'inventory_hold_id' => $hold->id,
                ])->save();

                $this->resizeRooms($booking, $stay->rooms, $categoryChanged);
            });
        } catch (NotEnoughRooms $exception) {
            throw new BookingNotPossible([new QuoteIssue('not_available', $exception->getMessage(), ['available' => $exception->available])]);
        }

        $this->audit('bookings.modified', 'Booking changed', $booking, $by, [
            'old' => $before,
            'new' => $this->stayData($booking),
            'price_overridden' => $booking->price_overridden,
            'override_reason' => $booking->override_reason,
        ]);
    }

    public function assignRoom(Booking $booking, int $position, Room $room, ?User $by = null): void
    {
        $this->requireStatus($booking, [BookingStatus::Tentative, BookingStatus::Confirmed, BookingStatus::CheckedIn], __('Rooms can only be given to current bookings.'));

        DB::transaction(function () use ($booking, $position, $room, $by): void {
            // Lock the room so two receptionists cannot give it away at once.
            Room::query()->whereKey($room->id)->lockForUpdate()->first();

            $line = BookingRoom::query()->where('booking_id', $booking->id)->where('position', $position)->firstOrFail();

            if ($room->room_category_id !== $booking->room_category_id) {
                throw new BookingActionNotAllowed(__('This room is not in the booked room category.'));
            }

            $usedByThisBooking = BookingRoom::query()
                ->where('booking_id', $booking->id)
                ->where('position', '!=', $position)
                ->where('room_id', $room->id)
                ->exists();

            if ($usedByThisBooking || ! $this->assignments->canAssign($booking, $room)) {
                throw new BookingActionNotAllowed(__('Room :number is not free for these nights.', ['number' => $room->number]));
            }

            $previous = $line->room_id;
            $line->forceFill(['room_id' => $room->id])->save();

            $moved = $booking->status === BookingStatus::CheckedIn && $previous !== null && $previous !== $room->id;
            $this->audit($moved ? 'bookings.room_moved' : 'bookings.room_assigned', $moved ? 'Guest moved to another room' : 'Room number given', $booking, $by, [
                'position' => $position,
                'room' => $room->number,
                'previous_room_id' => $previous,
            ]);
        });
    }

    /**
     * @param  array<int, string>  $roomsByPosition  room IDs to give first (position => room ID)
     */
    public function checkIn(Booking $booking, array $roomsByPosition = [], ?User $by = null): void
    {
        $this->requireStatus($booking, [BookingStatus::Confirmed], __('Only confirmed bookings can be checked in.'));

        $today = $this->today();

        if ($booking->check_in->gt($today)) {
            throw new BookingActionNotAllowed(__('This guest arrives on :date.', ['date' => $booking->check_in->toDateString()]));
        }

        if ($booking->check_out->lte($today)) {
            throw new BookingActionNotAllowed(__('This stay has already ended.'));
        }

        DB::transaction(function () use ($booking, $roomsByPosition, $by): void {
            foreach ($roomsByPosition as $position => $roomId) {
                $room = Room::query()->whereKey($roomId)->first() ?? throw new BookingActionNotAllowed(__('Unknown room.'));
                $this->assignRoom($booking, (int) $position, $room, $by);
            }

            if ($booking->bookingRooms()->whereNull('room_id')->exists()) {
                throw new BookingActionNotAllowed(__('Give every room a room number before check-in.'));
            }

            $booking->forceFill([
                'status' => BookingStatus::CheckedIn,
                'checked_in_at' => now(),
                'checked_in_by' => $by?->getKey(),
            ])->save();
        });

        $this->audit('bookings.checked_in', 'Checked in', $booking, $by);
    }

    /**
     * Check out. Before the departure date this is an early departure: the
     * stay ends today (the first night always counts) and the unused nights
     * go back on sale.
     */
    public function checkOut(Booking $booking, ?User $by = null): void
    {
        $this->requireStatus($booking, [BookingStatus::CheckedIn], __('Only guests who are checked in can check out.'));

        $today = $this->today();
        $newCheckOut = $today->max($booking->check_in->addDay());
        $originalCheckOut = $booking->check_out;
        $early = $newCheckOut->lt($originalCheckOut);

        DB::transaction(function () use ($booking, $by, $newCheckOut, $originalCheckOut, $early): void {
            if ($early) {
                $this->holds->unbook($booking->room_category_id, $newCheckOut, $originalCheckOut, $booking->rooms);
            }

            $booking->forceFill([
                'status' => BookingStatus::CheckedOut,
                'check_out' => $early ? $newCheckOut : $originalCheckOut,
                'checked_out_at' => now(),
                'checked_out_by' => $by?->getKey(),
            ])->save();
        });

        $this->audit($early ? 'bookings.early_departure' : 'bookings.checked_out', $early ? 'Checked out early' : 'Checked out', $booking, $by, $early ? [
            'planned_check_out' => $originalCheckOut->toDateString(),
            'nights_freed' => (int) $newCheckOut->diffInDays($originalCheckOut),
        ] : []);
    }

    /**
     * Cancel tentative bookings whose deadline has passed (current hotel).
     */
    public function expireTentative(): int
    {
        $count = 0;

        Booking::query()
            ->where('status', BookingStatus::Tentative->value)
            ->where('tentative_until', '<=', now())
            ->get()
            ->each(function (Booking $booking) use (&$count): void {
                $this->cancel($booking, __('Tentative booking not confirmed in time'), expired: true);
                $count++;
            });

        return $count;
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->context->hotel()->timezone)->startOfDay();
    }

    /**
     * Give back what the booking holds on these nights: its hold if it is
     * tentative, its booked rooms otherwise.
     */
    private function freeNights(Booking $booking, CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($booking->status === BookingStatus::Tentative) {
            $hold = $this->hold($booking);

            if ($hold->status === HoldStatus::Active) {
                $this->holds->release($hold);
            }

            return;
        }

        $this->holds->unbook($booking->room_category_id, $from, $to, $booking->rooms);
    }

    private function hold(Booking $booking): InventoryHold
    {
        return InventoryHold::query()->whereKey((string) $booking->inventory_hold_id)->firstOrFail();
    }

    private function resizeRooms(Booking $booking, int $rooms, bool $clearAll): void
    {
        BookingRoom::query()->where('booking_id', $booking->id)->where('position', '>', $rooms)->delete();

        $existing = BookingRoom::query()->where('booking_id', $booking->id)->pluck('position')->all();
        foreach (range(1, $rooms) as $position) {
            if (! in_array($position, $existing, true)) {
                BookingRoom::query()->create(['booking_id' => $booking->id, 'position' => $position]);
            }
        }

        // Room numbers that no longer fit the new stay are removed.
        foreach (BookingRoom::query()->where('booking_id', $booking->id)->whereNotNull('room_id')->with('room')->get() as $line) {
            if ($clearAll || $line->room === null || ! $this->assignments->canAssign($booking, $line->room)) {
                $line->forceFill(['room_id' => null])->save();
            }
        }
    }

    /**
     * @param  list<BookingStatus>  $allowed
     */
    private function requireStatus(Booking $booking, array $allowed, string $message): void
    {
        if (! in_array($booking->status, $allowed, true)) {
            throw new BookingActionNotAllowed($message);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stayData(Booking $booking): array
    {
        return [
            'room_category_id' => $booking->room_category_id,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'rooms' => $booking->rooms,
            'adults' => $booking->adults,
            'children_ages' => $booking->children_ages,
            'total_minor' => $booking->total_minor,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(string $event, string $description, Booking $booking, ?User $by, array $properties = []): void
    {
        Audit::record($event, $description, subject: $booking, causer: $by, properties: ['reference' => $booking->reference, ...$properties]);
    }
}
