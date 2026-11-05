<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\StaffAlerts\OwnerSummarySender;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('owners:daily-summary')]
#[Description('Send owners their daily summary at the time they chose, in every hotel')]
final class SendOwnerSummaries extends Command
{
    public function handle(TenantContext $context, OwnerSummarySender $sender): int
    {
        $total = 0;
        $context->eachHotel(function () use ($sender, &$total): void {
            $total += $sender->sendDue();
        });

        $this->components->info("{$total} summary(ies) sent.");

        return self::SUCCESS;
    }
}
