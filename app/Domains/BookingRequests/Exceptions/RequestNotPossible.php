<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests\Exceptions;

use RuntimeException;

/**
 * A request cannot be created or decided as asked. The message is shown to
 * the assistant or to staff; the code is stable.
 */
final class RequestNotPossible extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(public readonly string $reason, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
