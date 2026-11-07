<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Listeners;

use App\Domains\Onboarding\Signups;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

final class CompleteSignup
{
    public function __construct(private readonly Signups $signups) {}

    public function handle(Verified $event): void
    {
        if ($event->user instanceof User) {
            $this->signups->complete($event->user);
        }
    }
}
