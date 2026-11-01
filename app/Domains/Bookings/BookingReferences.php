<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues booking references such as FCL-26-0042: hotel prefix, two-digit
 * year, and a sequence per hotel and year. The sequence row is locked, so
 * concurrent bookings never get the same number.
 */
final class BookingReferences
{
    public function __construct(private readonly TenantContext $context) {}

    public function next(): string
    {
        $hotel = $this->context->hotel();
        $year = (int) now($hotel->timezone)->format('Y');

        return DB::transaction(function () use ($hotel, $year): string {
            DB::table('booking_sequences')->insertOrIgnore([
                'hotel_id' => $hotel->getKey(),
                'year' => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('booking_sequences')
                ->where('hotel_id', $hotel->getKey())
                ->where('year', $year)
                ->lockForUpdate()
                ->first(['id', 'last_number']);

            $number = (int) ($row->last_number ?? 0) + 1;
            DB::table('booking_sequences')->where('id', $row->id ?? 0)->update(['last_number' => $number, 'updated_at' => now()]);

            return sprintf('%s-%s-%04d', $this->prefix($hotel), substr((string) $year, -2), $number);
        });
    }

    public function prefix(Hotel $hotel): string
    {
        $configured = HotelSettings::current()->booking_prefix;

        return $configured !== null && $configured !== '' ? $configured : self::defaultPrefix($hotel->name);
    }

    public static function defaultPrefix(string $hotelName): string
    {
        $initials = collect(preg_split('/[^\pL\pN]+/u', Str::ascii($hotelName)) ?: [])
            ->filter()
            ->map(fn (string $word): string => strtoupper($word[0]))
            ->implode('');

        return substr($initials !== '' ? $initials : 'BK', 0, 4);
    }
}
