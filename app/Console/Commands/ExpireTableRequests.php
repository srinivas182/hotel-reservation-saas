<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Restaurant\TableRequests;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('table-requests:expire')]
#[Description('Expire WhatsApp table requests nobody answered in time, in every hotel')]
final class ExpireTableRequests extends Command
{
    public function handle(TenantContext $context, TableRequests $requests): int
    {
        $total = 0;
        $context->eachHotel(function () use ($requests, &$total): void {
            $total += $requests->expireDue();
        });
        $this->components->info("{$total} table request(s) expired.");

        return self::SUCCESS;
    }
}
