<?php

declare(strict_types=1);

namespace App\Domains\Events\Exceptions;

use RuntimeException;

final class EventNotPossible extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason, match ($reason) {
            'taken' => __('The space is already held or confirmed at that time.'),
            'no_space' => __('Choose a space and a time block first.'),
            'capacity' => __('The space is too small for this number of people in this layout.'),
            'status' => __('This event cannot be changed that way.'),
            'past' => __('The date has passed.'),
            'no_quote' => __('The client must accept a quote first.'),
            default => __('This is not possible.'),
        });
    }
}
