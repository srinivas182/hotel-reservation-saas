<?php

declare(strict_types=1);

namespace App\Domains\Events;

enum EventType: string
{
    case Seminar = 'seminar';
    case Meeting = 'meeting';
    case Wedding = 'wedding';
    case Party = 'party';
    case Conference = 'conference';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Seminar => __('Seminar'),
            self::Meeting => __('Meeting'),
            self::Wedding => __('Wedding'),
            self::Party => __('Private party'),
            self::Conference => __('Conference'),
            self::Other => __('Other'),
        };
    }
}
