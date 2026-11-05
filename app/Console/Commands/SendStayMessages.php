<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\StayMessages\StayMessenger;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stay-messages:send')]
#[Description('Send due arrival reminders, check-out reminders and thank-you messages, in every hotel')]
final class SendStayMessages extends Command
{
    public function handle(TenantContext $context, StayMessenger $messenger): int
    {
        $total = 0;
        $context->eachHotel(function () use ($messenger, &$total): void {
            $total += $messenger->sendDue();
        });

        $this->components->info("{$total} stay message(s) sent.");

        return self::SUCCESS;
    }
}
