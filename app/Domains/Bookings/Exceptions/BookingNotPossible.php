<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Exceptions;

use App\Domains\Rates\Quote\QuoteIssue;
use RuntimeException;

/**
 * The stay cannot be booked as asked (the quote reported issues, or the
 * rooms were taken while the form was open).
 */
final class BookingNotPossible extends RuntimeException
{
    /**
     * @param  list<QuoteIssue>  $issues
     */
    public function __construct(public readonly array $issues, string $message = '')
    {
        parent::__construct($message !== '' ? $message : implode(' ', array_map(fn (QuoteIssue $issue): string => $issue->message, $issues)));
    }
}
