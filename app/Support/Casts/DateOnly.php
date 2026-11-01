<?php

declare(strict_types=1);

namespace App\Support\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A calendar date with no time, stored as "Y-m-d" on every database.
 *
 * Laravel's "date" cast stores "Y-m-d 00:00:00", which SQLite then compares
 * as text, so range queries such as from_date <= '2026-11-20' go wrong.
 * Storing the bare date keeps queries correct on SQLite and MySQL.
 *
 * @implements CastsAttributes<CarbonImmutable, mixed>
 */
final class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof CarbonInterface => $value->toDateString(),
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 => substr($value, 0, 10),
            default => throw new InvalidArgumentException("Invalid date for [{$key}]."),
        };
    }
}
