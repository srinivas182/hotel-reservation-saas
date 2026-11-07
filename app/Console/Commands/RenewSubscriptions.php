<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Billing\Subscriptions;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:renew')]
#[Description('Start the next period of subscriptions that are due (trial end, renewal, scheduled downgrade), in every hotel')]
final class RenewSubscriptions extends Command
{
    public function handle(TenantContext $context, Subscriptions $subscriptions): int
    {
        $total = 0;
        $context->eachHotel(function () use ($subscriptions, &$total): void {
            // A hotel that was off for a while catches up period by period.
            for ($i = 0; $i < 24 && $subscriptions->renewIfDue(); $i++) {
                $total++;
            }
        });
        $this->components->info("{$total} period(s) started.");

        return self::SUCCESS;
    }
}
