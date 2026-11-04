<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A request was approved, declined or expired. The guest is told in S6.3.
 */
final class BookingRequestDecided
{
    use Dispatchable;

    public function __construct(public readonly string $requestId) {}
}
