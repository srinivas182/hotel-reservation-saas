<?php

declare(strict_types=1);

namespace App\Domains\Orders;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order moved to a new status (guest messages in S11.3, room bill in S11.2).
 */
final class OrderChanged
{
    use Dispatchable;

    public function __construct(public readonly string $orderId) {}
}
