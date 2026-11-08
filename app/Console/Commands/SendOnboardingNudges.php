<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Onboarding\Nudges;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('onboarding:nudges')]
#[Description('Send setup reminders and trial-ending emails, in every hotel')]
final class SendOnboardingNudges extends Command
{
    public function handle(TenantContext $context, Nudges $nudges): int
    {
        $total = 0;
        $context->eachHotel(function () use ($nudges, &$total): void {
            $total += $nudges->run();
        });
        $this->components->info("{$total} onboarding email(s) sent.");

        return self::SUCCESS;
    }
}
