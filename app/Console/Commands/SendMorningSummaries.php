<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\StaffAlerts\Morning\MorningSummarySender;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('staff:morning-summary')]
#[Description('Send the morning WhatsApp summary to everyone whose time has come, in every hotel')]
final class SendMorningSummaries extends Command
{
    public function handle(TenantContext $context, MorningSummarySender $sender): int
    {
        $total = 0;
        $context->eachHotel(function () use ($sender, &$total): void {
            $total += $sender->sendDue();
        });

        $this->components->info("{$total} morning summary(ies) sent.");

        return self::SUCCESS;
    }
}
