<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Conversations\SilenceWatch;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('conversations:check-waiting')]
#[Description('Send one holding message to guests waiting too long for staff, in every hotel')]
final class CheckGuestsWaiting extends Command
{
    public function handle(TenantContext $context, SilenceWatch $watch): int
    {
        $total = 0;
        $context->eachHotel(function () use ($watch, &$total): void {
            $total += $watch->check();
        });

        $this->components->info("{$total} waiting guest(s) answered.");

        return self::SUCCESS;
    }
}
