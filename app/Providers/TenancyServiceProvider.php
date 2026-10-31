<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Audit\Listeners\RecordAuthenticationEvents;
use App\Domains\Tenancy\Queue\QueuedHotelContext;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Wires hotel data separation (ADR-001, ADR-004) and the audit listeners.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: reset between requests (Octane) and between queued jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(QueuedHotelContext::class);
    }

    public function boot(): void
    {
        Queue::createPayloadUsing(
            fn (?string $connection, ?string $queue, array $payload): array => $this->app->make(QueuedHotelContext::class)->payload($payload),
        );

        Event::listen(JobProcessing::class, [QueuedHotelContext::class, 'processing']);
        Event::listen(
            [JobProcessed::class, JobFailed::class, JobExceptionOccurred::class],
            [QueuedHotelContext::class, 'finished'],
        );

        Event::subscribe(RecordAuthenticationEvents::class);
    }
}
