<?php

declare(strict_types=1);

namespace App\Domains\Ai\Exceptions;

use RuntimeException;

/**
 * AI cannot be used right now. Callers hand the conversation to staff.
 * The reason code is stable: platform_off, hotel_off, budget, no_model,
 * all_failed.
 */
final class AiUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
