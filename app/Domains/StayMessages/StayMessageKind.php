<?php

declare(strict_types=1);

namespace App\Domains\StayMessages;

use App\Domains\Bookings\BookingStatus;

enum StayMessageKind: string
{
    case Reminder = 'reminder';
    case Checkout = 'checkout';
    case ThankYou = 'thank_you';

    public function label(): string
    {
        return match ($this) {
            self::Reminder => __('Arrival reminder (day before)'),
            self::Checkout => __('Check-out reminder (departure morning)'),
            self::ThankYou => __('Thank-you (departure day)'),
        };
    }

    public function defaultTime(): string
    {
        return match ($this) {
            self::Reminder => '10:00',
            self::Checkout => '08:00',
            self::ThankYou => '18:00',
        };
    }

    public function template(): string
    {
        return match ($this) {
            self::Reminder => 'stay_reminder',
            self::Checkout => 'checkout_reminder',
            self::ThankYou => 'stay_thank_you',
        };
    }

    /**
     * Bookings that should get this message today: which date to look at,
     * and which statuses.
     *
     * @return array{column: string, days_from_today: int, statuses: list<BookingStatus>}
     */
    public function target(): array
    {
        return match ($this) {
            self::Reminder => ['column' => 'check_in', 'days_from_today' => 1, 'statuses' => [BookingStatus::Confirmed]],
            self::Checkout => ['column' => 'check_out', 'days_from_today' => 0, 'statuses' => [BookingStatus::CheckedIn]],
            self::ThankYou => ['column' => 'check_out', 'days_from_today' => 0, 'statuses' => [BookingStatus::CheckedOut]],
        };
    }

    public function defaultText(string $locale): string
    {
        $text = match ($this) {
            self::Reminder => 'Hello {name}, we look forward to welcoming you at {hotel} tomorrow ({check_in}). Check-in is from {check_in_time}. Directions: {directions}. Reply to this message if you need anything.',
            self::Checkout => 'Good morning {name}, check-out at {hotel} is until {check_out_time} today. Reply to this message if you would like a late check-out. Thank you for your stay!',
            self::ThankYou => 'Thank you {name} for staying at {hotel}. We hope to see you again soon. Your opinion helps us: {review_url}. Have a good trip!',
        };

        return __($text, locale: $locale);
    }
}
