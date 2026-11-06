<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Events\EventService;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('events:expire-options')]
#[Description('Release event options past their date and move up the waiting list, in every hotel')]
final class ExpireEventOptions extends Command
{
    public function handle(TenantContext $context, EventService $events): int
    {
        $total = 0;
        $context->eachHotel(function () use ($events, &$total): void {
            $total += $events->expireOptions();
        });
        $this->components->info("{$total} option(s) expired.");

        return self::SUCCESS;
    }
}
