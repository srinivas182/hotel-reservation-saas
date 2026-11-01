<?php

declare(strict_types=1);

namespace App\Domains\Availability;

use App\Domains\Audit\Audit;
use App\Domains\Availability\Exceptions\NotEnoughRooms;
use App\Domains\Availability\Models\InventoryDay;
use App\Domains\Availability\Models\InventoryHold;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Places, extends, releases, expires and converts room holds.
 *
 * Writers lock the inventory_days rows of every night involved (created if
 * missing), always in date order, inside one transaction; availability is
 * then checked and the hold written before the locks are released. Two
 * requests for the last room are therefore serialised: exactly one wins.
 */
final class HoldService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AvailabilityService $availability,
    ) {}

    public function place(
        RoomCategory $category,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
        int $rooms,
        string $sourceType = 'manual',
        ?string $sourceId = null,
        ?User $by = null,
        ?string $note = null,
        ?int $minutes = null,
    ): InventoryHold {
        if ($rooms < 1 || $checkOut->lte($checkIn)) {
            throw new InvalidArgumentException('A hold needs at least one room and check-out after check-in.');
        }

        $minutes ??= HotelSettings::current()->hold_minutes;

        $hold = DB::transaction(function () use ($category, $checkIn, $checkOut, $rooms, $sourceType, $sourceId, $by, $note, $minutes): InventoryHold {
            $this->lockNights($category->id, $checkIn, $checkOut);
            $this->ensureAvailable($category->id, $checkIn, $checkOut, $rooms);

            return InventoryHold::query()->create([
                'room_category_id' => $category->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'rooms' => $rooms,
                'status' => HoldStatus::Active,
                'expires_at' => now()->addMinutes($minutes),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'note' => $note,
                'created_by' => $by?->getKey(),
            ]);
        });

        if ($sourceType === 'manual') {
            Audit::record('availability.hold_placed', 'Rooms held', subject: $this->context->hotel(), causer: $by, properties: $this->auditData($hold));
        }

        return $hold;
    }

    /**
     * Extend an active hold by the hotel's hold time (or the given minutes).
     * An expired hold can be revived only if the rooms are still free.
     */
    public function extend(InventoryHold $hold, ?int $minutes = null): InventoryHold
    {
        $minutes ??= HotelSettings::current()->hold_minutes;

        return DB::transaction(function () use ($hold, $minutes): InventoryHold {
            $this->lockNights($hold->room_category_id, $hold->check_in, $hold->check_out);
            $hold->refresh();

            if (! in_array($hold->status, [HoldStatus::Active, HoldStatus::Expired], true)) {
                throw new InvalidArgumentException('Only active or expired holds can be extended.');
            }

            if (! $hold->isCounting()) {
                $this->ensureAvailable($hold->room_category_id, $hold->check_in, $hold->check_out, $hold->rooms, $hold->id);
            }

            $hold->forceFill(['status' => HoldStatus::Active, 'expires_at' => now()->addMinutes($minutes)])->save();

            return $hold;
        });
    }

    public function release(InventoryHold $hold, ?User $by = null): void
    {
        if ($hold->status !== HoldStatus::Active) {
            return;
        }

        $hold->forceFill(['status' => HoldStatus::Released])->save();

        if ($hold->source_type === 'manual') {
            Audit::record('availability.hold_released', 'Held rooms released', subject: $this->context->hotel(), causer: $by, properties: $this->auditData($hold));
        }
    }

    /**
     * Turn a hold into booked rooms (used by bookings in S3).
     */
    public function convert(InventoryHold $hold): void
    {
        DB::transaction(function () use ($hold): void {
            $days = $this->lockNights($hold->room_category_id, $hold->check_in, $hold->check_out);
            $hold->refresh();

            if (! $hold->isCounting()) {
                // The hold lapsed: the rooms must still be free to book.
                $this->ensureAvailable($hold->room_category_id, $hold->check_in, $hold->check_out, $hold->rooms, $hold->id);
            }

            foreach ($days as $day) {
                $day->increment('booked', $hold->rooms);
            }

            $hold->forceFill(['status' => HoldStatus::Converted])->save();
        });
    }

    /**
     * Give back booked rooms for a range of nights (cancellation, no-show,
     * early departure, or the old dates of a changed booking).
     */
    public function unbook(string $categoryId, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $rooms): void
    {
        if ($checkOut->lte($checkIn) || $rooms < 1) {
            return;
        }

        DB::transaction(function () use ($categoryId, $checkIn, $checkOut, $rooms): void {
            foreach ($this->lockNights($categoryId, $checkIn, $checkOut) as $day) {
                $day->forceFill(['booked' => max(0, $day->booked - $rooms)])->save();
            }
        });
    }

    /**
     * Mark lapsed holds as expired in the current hotel. Lapsed holds already
     * stopped counting when they lapsed; this keeps statuses accurate.
     */
    public function expireDue(): int
    {
        return InventoryHold::query()
            ->where('status', HoldStatus::Active->value)
            ->where('expires_at', '<=', now())
            ->update(['status' => HoldStatus::Expired->value, 'updated_at' => now()]);
    }

    /**
     * Lock (creating if needed) the inventory rows for each night, in date order.
     *
     * @return list<InventoryDay>
     */
    public function lockNights(string $categoryId, CarbonImmutable $checkIn, CarbonImmutable $checkOut): array
    {
        $hotelId = $this->context->id();
        $now = now();
        $dates = [];

        foreach (CarbonPeriod::create($checkIn, $checkOut->subDay()) as $night) {
            $dates[] = $night->toDateString();
        }

        DB::table('inventory_days')->insertOrIgnore(array_map(fn (string $date): array => [
            'hotel_id' => $hotelId,
            'room_category_id' => $categoryId,
            'date' => $date,
            'held_back' => 0,
            'booked' => 0,
            'stop_sell' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $dates));

        return array_values(InventoryDay::query()
            ->where('room_category_id', $categoryId)
            ->whereIn('date', $dates)
            ->orderBy('date')
            ->lockForUpdate()
            ->get()
            ->all());
    }

    private function ensureAvailable(string $categoryId, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $rooms, ?string $ignoreHoldId = null): void
    {
        $available = $this->availability->availableFor($categoryId, $checkIn, $checkOut->subDay(), $ignoreHoldId);

        foreach ($available as $date => $free) {
            if ($free < $rooms) {
                throw new NotEnoughRooms($date, $free);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditData(InventoryHold $hold): array
    {
        return [
            'hold_id' => $hold->id,
            'category_id' => $hold->room_category_id,
            'check_in' => $hold->check_in->toDateString(),
            'check_out' => $hold->check_out->toDateString(),
            'rooms' => $hold->rooms,
        ];
    }
}
