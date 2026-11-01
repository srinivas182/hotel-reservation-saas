<?php

declare(strict_types=1);

namespace App\Domains\Availability\Exceptions;

use RuntimeException;

final class NotEnoughRooms extends RuntimeException
{
    public function __construct(public readonly string $date, public readonly int $available)
    {
        parent::__construct(__('Not enough rooms are available for these dates.'));
    }
}
