<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Queue;

use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\Tenancy\Exceptions\MissingHotelContext;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Carries the current hotel through the queue.
 *
 * On dispatch the hotel ID is written into the job payload; jobs that
 * implement RequiresHotelContext cannot be dispatched without one. When the
 * job runs, that hotel becomes current, and the previous context is restored
 * afterwards (so a synchronous job never leaks or clears a request's hotel).
 */
final class QueuedHotelContext
{
    /** @var list<Hotel|null> */
    private array $stack = [];

    /**
     * TenantContext is scoped (rebuilt per request and per job), so it is
     * resolved on every call rather than captured once.
     */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function payload(array $payload): array
    {
        $command = is_array($payload['data'] ?? null) ? ($payload['data']['commandName'] ?? null) : null;
        $jobClass = match (true) {
            is_object($command) => $command::class,
            is_string($command) => $command,
            default => '',
        };

        if (
            is_subclass_of($jobClass, RequiresHotelContext::class)
            && ! $this->context()->has()
        ) {
            throw MissingHotelContext::forJob($jobClass);
        }

        return ['hotel_id' => $this->context()->idOrNull()];
    }

    public function processing(JobProcessing $event): void
    {
        $this->stack[] = $this->context()->has() ? $this->context()->hotel() : null;

        $hotelId = $event->job->payload()['hotel_id'] ?? null;

        if (! is_string($hotelId)) {
            $this->context()->forget();

            return;
        }

        $hotel = Hotel::query()->find($hotelId);

        if ($hotel === null) {
            $this->context()->forget();
            throw MissingHotelContext::forAccess();
        }

        $this->context()->set($hotel);
    }

    public function finished(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        $previous = array_pop($this->stack);

        $previous === null ? $this->context()->forget() : $this->context()->set($previous);
    }
}
