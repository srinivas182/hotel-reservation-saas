<?php

declare(strict_types=1);

namespace App\Domains\Orders;

enum OrderStatus: string
{
    case New = 'new';
    case Accepted = 'accepted';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::Accepted => __('Accepted'),
            self::Ready => __('Ready'),
            self::Delivered => __('Delivered'),
            self::Declined => __('Declined'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Accepted, self::Declined, self::Cancelled],
            self::Accepted => [self::Ready, self::Cancelled],
            self::Ready => [self::Delivered],
            default => [],
        };
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::New, self::Accepted, self::Ready];
    }
}
