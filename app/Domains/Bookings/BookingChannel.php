<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

enum BookingChannel: string
{
    case WalkIn = 'walk_in';
    case Phone = 'phone';
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case BookingCom = 'booking_com';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => __('Walk-in'),
            self::Phone => __('Phone'),
            self::Email => __('Email'),
            self::WhatsApp => 'WhatsApp',
            self::BookingCom => 'Booking.com',
            self::Other => __('Other'),
        };
    }
}
