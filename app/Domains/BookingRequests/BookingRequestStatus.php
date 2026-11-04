<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests;

enum BookingRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Waiting for reception'),
            self::Approved => __('Approved'),
            self::Declined => __('Declined'),
            self::Expired => __('Expired'),
            self::Withdrawn => __('Replaced by a new request'),
        };
    }
}
