<?php

declare(strict_types=1);

namespace App\Domains\Events;

use App\Domains\Audit\Audit;
use App\Domains\Availability\HoldService;
use App\Domains\Availability\HoldStatus;
use App\Domains\Availability\Models\InventoryHold;
use App\Domains\Bookings\BookingChannel;
use App\Domains\Bookings\BookingService;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\NewBooking;
use App\Domains\Events\Exceptions\EventNotPossible;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\RoomBlock;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attendee room blocks: an inventory hold until the cut-off date, bookings
 * taken from it at the group rate, and the rest released at the cut-off.
 */
final class RoomBlocks
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly HoldService $holds,
        private readonly BookingService $bookings,
    ) {}

    public function create(Event $event, RoomCategory $category, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $rooms, int $rateMinor, CarbonImmutable $cutoff, User $by): RoomBlock
    {
        $hotel = $this->context->hotel();
        $today = HotelClock::today($hotel);

        if ($rooms < 1 || $checkOut->lte($checkIn) || $cutoff->lt($today) || $cutoff->gt($checkIn)) {
            throw new EventNotPossible('block', (string) __('Check the dates: the cut-off must be from today and no later than the arrival.'));
        }

        // The hold counts until the end of the cut-off day (hotel time).
        $until = CarbonImmutable::parse($cutoff->toDateString().' 23:59:59', $hotel->timezone)->utc();
        $minutes = max(1, (int) ceil(now()->diffInSeconds($until) / 60));

        $block = DB::transaction(function () use ($event, $category, $checkIn, $checkOut, $rooms, $rateMinor, $cutoff, $by, $minutes): RoomBlock {
            $block = RoomBlock::query()->create([
                'event_id' => $event->id, 'room_category_id' => $category->id, 'check_in' => $checkIn, 'check_out' => $checkOut,
                'rooms' => $rooms, 'rate_minor' => $rateMinor, 'cutoff_date' => $cutoff, 'created_by' => $by->getKey(),
            ]);

            try {
                $hold = $this->holds->place($category, $checkIn, $checkOut, $rooms, sourceType: 'event_block', sourceId: (string) $block->id, by: $by, note: $event->reference, minutes: $minutes);
            } catch (\Throwable) {
                throw new EventNotPossible('rooms', (string) __('Not enough rooms are free for those nights.'));
            }

            $block->forceFill(['inventory_hold_id' => $hold->id])->save();

            return $block;
        });

        Audit::record('events.room_block', 'Rooms blocked for the event', subject: $event, causer: $by, properties: ['category' => $category->code, 'rooms' => $rooms, 'from' => $checkIn->toDateString(), 'to' => $checkOut->toDateString(), 'cutoff' => $cutoff->toDateString()]);

        return $block;
    }

    /**
     * Book one room from the block for an attendee, at the group rate.
     *
     * @param  array{first_name: string, last_name?: string|null, phone?: string|null, email?: string|null}  $guest
     */
    public function book(RoomBlock $block, array $guest, int $adults, User $by): Booking
    {
        $event = Event::query()->findOrFail($block->event_id);

        return DB::transaction(function () use ($block, $guest, $adults, $by, $event): Booking {
            $block = RoomBlock::query()->lockForUpdate()->findOrFail($block->id);
            $hold = InventoryHold::query()->lockForUpdate()->find($block->inventory_hold_id);

            if ($block->remaining() < 1 || $hold === null || ! $hold->isCounting()) {
                throw new EventNotPossible('block_full', (string) __('No room is left in this block.'));
            }

            // Free one room from the block, then book it in the same transaction.
            $hold->forceFill($hold->rooms > 1 ? ['rooms' => $hold->rooms - 1] : ['status' => HoldStatus::Converted])->save();
            $block->forceFill(['booked_rooms' => $block->booked_rooms + 1])->save();

            $nights = (int) $block->check_in->diffInDays($block->check_out);
            $booking = $this->bookings->create(new NewBooking(
                stay: new StayRequest($block->room_category_id, $block->check_in, $block->check_out, adults: $adults, rooms: 1),
                guest: $guest,
                channel: BookingChannel::Phone,
                groupLabel: $event->reference,
                overrideTotal: Money::ofMinor($block->rate_minor * $nights, $this->context->hotel()->currency),
                overrideReason: (string) __('Group rate for event :reference', ['reference' => $event->reference]),
            ), $by);
            $booking->forceFill(['event_id' => $event->id])->save();

            Audit::record('events.block_booking', 'Room booked from the event block', subject: $event, causer: $by, properties: ['booking' => $booking->reference, 'block' => $block->id]);

            return $booking;
        });
    }

    public function release(RoomBlock $block, ?User $by = null): void
    {
        if ($block->released_at !== null) {
            return;
        }

        DB::transaction(function () use ($block): void {
            $hold = InventoryHold::query()->lockForUpdate()->find($block->inventory_hold_id);
            if ($hold !== null && $hold->status === HoldStatus::Active) {
                $hold->forceFill(['status' => HoldStatus::Released])->save();
            }
            $block->forceFill(['released_at' => now()])->save();
        });

        Audit::record('events.block_released', 'Unused event rooms put back on sale', subject: Event::query()->find($block->event_id), causer: $by, properties: ['block' => $block->id, 'unused' => $block->rooms - $block->booked_rooms]);
    }

    /**
     * Blocks past their cut-off: unused rooms are already back on sale (the
     * hold stopped counting); record it.
     */
    public function releaseDue(): int
    {
        $today = HotelClock::today($this->context->hotel());
        $count = 0;

        foreach (RoomBlock::query()->whereNull('released_at')->where('cutoff_date', '<', $today->toDateString())->get() as $block) {
            $this->release($block);
            $count++;
        }

        return $count;
    }
}
