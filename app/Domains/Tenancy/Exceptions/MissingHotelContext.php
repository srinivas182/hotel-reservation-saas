<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown whenever hotel-owned data is touched without a current hotel.
 *
 * This is the "fail closed" guarantee from ADR-004: a missing context is a
 * programming error and must never degrade into an unscoped query.
 */
final class MissingHotelContext extends RuntimeException
{
    public static function forQuery(string $model): self
    {
        return new self("No current hotel is set; refusing to query hotel-owned model [{$model}].");
    }

    public static function forAccess(): self
    {
        return new self('No current hotel is set.');
    }

    public static function forJob(string $job): self
    {
        return new self("Job [{$job}] requires a hotel context but was dispatched without one.");
    }
}
