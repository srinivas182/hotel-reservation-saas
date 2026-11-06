<?php

declare(strict_types=1);

namespace App\Domains\Orders\Exceptions;

use RuntimeException;

final class OrderNotPossible extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason, match ($reason) {
            'no_items' => __('Add at least one dish.'),
            'not_orderable' => __('One of the dishes cannot be ordered now.'),
            'closed' => __('This outlet does not take orders now.'),
            'not_staying' => __('Only guests staying at the hotel can charge an order to their room.'),
            'no_room' => __('Say which room the order goes to.'),
            'status' => __('This order cannot be changed that way.'),
            default => __('This order is not possible.'),
        });
    }
}
