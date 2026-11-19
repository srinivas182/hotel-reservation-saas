<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Deposits\Deposits;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deposits:remind')]
#[Description('Remind guests whose rooms are released soon to pay their deposit, in every hotel')]
final class RemindDeposits extends Command
{
    public function handle(TenantContext $context, Deposits $deposits): int
    {
        $total = 0;

        $context->eachHotel(function () use ($deposits, &$total): void {
            $total += $deposits->remindDue();
        });

        $this->components->info("{$total} reminder(s) sent.");

        return self::SUCCESS;
    }
}
