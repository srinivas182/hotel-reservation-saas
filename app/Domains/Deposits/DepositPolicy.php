<?php

declare(strict_types=1);

namespace App\Domains\Deposits;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\Models\Hotel;

/**
 * The hotel's deposit refund rule (Sprint F): refunded when the booking is
 * cancelled at least N days before arrival, kept otherwise; or never.
 */
final class DepositPolicy
{
    public static function text(HotelAssistantSettings $settings, string $locale): string
    {
        $days = $settings->deposit_refund_days;

        return match (true) {
            $days === null => (string) __('The deposit is not refundable.', locale: $locale),
            $days === 0 => (string) __('The deposit is refunded if you cancel before your arrival day; after that, it is kept.', locale: $locale),
            default => (string) trans_choice(
                'The deposit is refunded if you cancel at least :count day before arrival; after that, it is kept.|The deposit is refunded if you cancel at least :count days before arrival; after that, it is kept.',
                $days, [], $locale,
            ),
        };
    }

    /** Cancelled now, in hotel time: is the deposit owed back? */
    public static function refundable(Booking $booking, HotelAssistantSettings $settings, Hotel $hotel): bool
    {
        $days = $settings->deposit_refund_days;

        if ($days === null) {
            return false;
        }

        return HotelClock::today($hotel)->addDays($days)->lte($booking->check_in) && ($days > 0 || HotelClock::today($hotel)->lt($booking->check_in));
    }
}
