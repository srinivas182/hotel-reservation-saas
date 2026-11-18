<?php

declare(strict_types=1);

namespace App\Domains\StaffActions;

use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Carries out a WhatsApp decision once its undo window has passed. The
 * staff-actions:run-due sweep catches any job that was lost.
 */
final class RunStaffAction implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    /** Retry policy (S16): the claim makes a repeat harmless. */
    public int $tries = 3;

    /** @var list<int> seconds between attempts */
    public array $backoff = [5, 30];

    /** Seconds before a stuck attempt is stopped. */
    public int $timeout = 60;

    public function __construct(public readonly string $staffActionId) {}

    public function handle(StaffActionRouter $router): void
    {
        $router->execute($this->staffActionId);
    }
}
