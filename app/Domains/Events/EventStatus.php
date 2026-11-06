<?php

declare(strict_types=1);

namespace App\Domains\Events;

enum EventStatus: string
{
    case Enquiry = 'enquiry';
    case Quoted = 'quoted';
    case Option = 'option';
    case Confirmed = 'confirmed';
    case Done = 'done';
    case Lost = 'lost';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Enquiry => __('Enquiry'),
            self::Quoted => __('Quoted'),
            self::Option => __('Option'),
            self::Confirmed => __('Confirmed'),
            self::Done => __('Done'),
            self::Lost => __('Lost'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Enquiry, self::Quoted, self::Option, self::Confirmed], true);
    }
}
