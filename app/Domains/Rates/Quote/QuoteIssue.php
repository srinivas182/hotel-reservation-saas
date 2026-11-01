<?php

declare(strict_types=1);

namespace App\Domains\Rates\Quote;

/**
 * Why a stay cannot be booked as asked. The code is stable (used by the
 * WhatsApp flow in S6); the message is for people.
 */
final readonly class QuoteIssue
{
    /**
     * @param  array<string, string|int>  $context
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $context = [],
    ) {}
}
