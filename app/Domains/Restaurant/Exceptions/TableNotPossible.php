<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Exceptions;

use RuntimeException;

final class TableNotPossible extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason, match ($reason) {
            'full' => __('There are not enough free seats at that time.'),
            'closed' => __('The outlet does not take bookings at that time.'),
            'too_many' => __('The party is larger than the outlet.'),
            'covers' => __('Give the number of people.'),
            'status' => __('This booking cannot be changed that way.'),
            'too_early' => __('This can only be done on the day of the booking.'),
            default => __('This booking is not possible.'),
        });
    }
}
