<?php

declare(strict_types=1);

namespace App\Domains\Restaurant;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\GuestDirectory;
use App\Domains\Restaurant\Exceptions\TableNotPossible;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\Models\TableBooking;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Table bookings: created, changed and moved through their statuses with
 * the outlet row locked, so two bookings can never take the same seats.
 */
final class TableBookingService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TableAvailability $availability,
        private readonly GuestDirectory $guests,
    ) {}

    public function create(NewTableBooking $data, ?User $by = null): TableBooking
    {
        $booking = DB::transaction(function () use ($data, $by): TableBooking {
            $outlet = Outlet::query()->lockForUpdate()->findOrFail($data->outletId);
            $problem = $this->availability->problem($outlet, $data->date, $data->time, $data->covers);

            if ($problem !== null) {
                throw TableNotPossible::because($problem);
            }

            $guest = $this->guests->findOrCreate($data->guest);

            return TableBooking::query()->create([
                'outlet_id' => $outlet->id,
                'guest_id' => $guest->id,
                'conversation_id' => $data->conversationId,
                'reference' => $this->nextReference(),
                'date' => $data->date,
                'time' => $data->time,
                'covers' => $data->covers,
                'duration_minutes' => $outlet->default_duration_minutes,
                'status' => $data->status,
                'source' => $data->source,
                'notes' => $data->notes,
                'expires_at' => $data->status === TableBookingStatus::Requested ? $data->expiresAt : null,
                'created_by' => $by?->getKey(),
            ]);
        });

        Audit::record('table_bookings.created', 'Table booking created', subject: $booking, causer: $by, properties: [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'covers' => $booking->covers,
            'date' => $booking->date->toDateString(),
            'time' => $booking->time,
        ]);

        return $booking;
    }

    public function change(TableBooking $booking, CarbonImmutable $date, string $time, int $covers, ?string $notes, User $by): TableBooking
    {
        if (! in_array($booking->status, [TableBookingStatus::Requested, TableBookingStatus::Confirmed], true)) {
            throw TableNotPossible::because('status');
        }

        $before = ['date' => $booking->date->toDateString(), 'time' => $booking->time, 'covers' => $booking->covers];

        DB::transaction(function () use ($booking, $date, $time, $covers, $notes): void {
            $outlet = Outlet::query()->lockForUpdate()->findOrFail($booking->outlet_id);
            $problem = $this->availability->problem($outlet, $date, $time, $covers, null, $booking->id);

            if ($problem !== null) {
                throw TableNotPossible::because($problem);
            }

            $booking->update(['date' => $date, 'time' => $time, 'covers' => $covers, 'notes' => $notes]);
        });

        Audit::record('table_bookings.changed', 'Table booking changed', subject: $booking, causer: $by, properties: [
            'old' => $before,
            'new' => ['date' => $date->toDateString(), 'time' => $time, 'covers' => $covers],
        ]);

        return $booking;
    }

    public function move(TableBooking $booking, TableBookingStatus $to, ?User $by = null, ?string $reason = null): TableBooking
    {
        $from = $booking->status;

        if (! in_array($to, $from->allowedNext(), true)) {
            throw TableNotPossible::because('status');
        }

        if (in_array($to, [TableBookingStatus::Arrived, TableBookingStatus::NoShow], true)
            && $booking->date->gt(HotelClock::today($this->context->hotel()))) {
            throw TableNotPossible::because('too_early');
        }

        $booking->forceFill([
            'status' => $to,
            ...(in_array($to, [TableBookingStatus::Confirmed, TableBookingStatus::Declined], true) ? ['decided_by' => $by?->getKey(), 'decided_at' => now(), 'expires_at' => null] : []),
            ...($to === TableBookingStatus::Declined ? ['decline_reason' => $reason] : []),
        ])->save();

        if ($from === TableBookingStatus::Requested && in_array($to, [TableBookingStatus::Confirmed, TableBookingStatus::Declined], true)) {
            TableBookingChanged::dispatch($booking->id);
        }

        Audit::record('table_bookings.'.$to->value, 'Table booking: '.$to->label(), subject: $booking, causer: $by, properties: [
            'reference' => $booking->reference,
            'from' => $from->value,
            'reason' => $reason,
        ]);

        return $booking;
    }

    private function nextReference(): string
    {
        $hotel = $this->context->hotel();
        $year = (int) now($hotel->timezone)->format('Y');

        DB::table('table_booking_sequences')->insertOrIgnore(['hotel_id' => $hotel->getKey(), 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('table_booking_sequences')->where('hotel_id', $hotel->getKey())->where('year', $year)->lockForUpdate()->first(['id', 'last_number']);
        $number = (int) ($row->last_number ?? 0) + 1;
        DB::table('table_booking_sequences')->where('id', $row->id ?? 0)->update(['last_number' => $number, 'updated_at' => now()]);

        return sprintf('T-%s-%04d', substr((string) $year, -2), $number);
    }
}
