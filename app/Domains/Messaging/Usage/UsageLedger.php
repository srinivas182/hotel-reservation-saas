<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Usage;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Writes usage for the current hotel. A source can only be counted once
 * per meter, so retries never double count.
 */
final class UsageLedger
{
    public function record(string $meter, int $quantity, string $sourceType, string $sourceId): void
    {
        try {
            UsageRecord::query()->create([
                'meter' => $meter,
                'quantity' => $quantity,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'occurred_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already counted.
        }
    }
}
