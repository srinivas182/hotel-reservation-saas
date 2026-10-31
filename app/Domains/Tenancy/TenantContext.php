<?php

declare(strict_types=1);

namespace App\Domains\Tenancy;

use App\Domains\Audit\Audit;
use App\Domains\Tenancy\Exceptions\MissingHotelContext;
use App\Domains\Tenancy\Models\Hotel;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;

/**
 * Holds the hotel the current request, job or command is working for.
 *
 * Registered as a scoped binding, so it is reset between requests (including
 * under Octane) and between queued jobs. All hotel-owned queries read the
 * hotel from here; when it is empty they throw (ADR-004).
 */
final class TenantContext
{
    private ?Hotel $hotel = null;

    private int $bypassDepth = 0;

    public function set(Hotel $hotel): void
    {
        $this->hotel = $hotel;
        Context::add('hotel_id', $hotel->getKey());
    }

    public function forget(): void
    {
        $this->hotel = null;
        Context::forget('hotel_id');
    }

    public function has(): bool
    {
        return $this->hotel !== null;
    }

    public function hotel(): Hotel
    {
        return $this->hotel ?? throw MissingHotelContext::forAccess();
    }

    public function id(): string
    {
        return (string) $this->hotel()->getKey();
    }

    public function idOrNull(): ?string
    {
        return $this->hotel === null ? null : (string) $this->hotel->getKey();
    }

    /**
     * Run a callback as the given hotel, restoring the previous context after.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Hotel $hotel, callable $callback): mixed
    {
        $previous = $this->hotel;
        $this->set($hotel);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->forget() : $this->set($previous);
        }
    }

    /**
     * Run a callback once per hotel (for console commands and scheduled work).
     *
     * @param  callable(Hotel): void  $callback
     */
    public function eachHotel(callable $callback): void
    {
        Hotel::query()->orderBy('id')->each(
            fn (Hotel $hotel) => $this->run($hotel, fn () => $callback($hotel)),
        );
    }

    /**
     * Platform-level access across all hotels. Every use is audited.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function bypass(string $reason, callable $callback): mixed
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to bypass hotel scoping.');
        }

        Audit::record('tenancy.bypass', 'Hotel scoping bypassed', properties: ['reason' => $reason]);

        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
