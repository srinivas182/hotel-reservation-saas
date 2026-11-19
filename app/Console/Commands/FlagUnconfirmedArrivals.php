<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Arrivals\ArrivalBoard;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('arrivals:flag-unconfirmed')]
#[Description('In the evening, flag tomorrow\'s guests who did not answer the arrival card, in every hotel')]
final class FlagUnconfirmedArrivals extends Command
{
    public function handle(TenantContext $context, ArrivalBoard $board): int
    {
        $total = 0;

        $context->eachHotel(function () use ($board, &$total): void {
            $total += $board->flagSilent();
        });

        $this->components->info("{$total} arrival(s) flagged.");

        return self::SUCCESS;
    }
}
