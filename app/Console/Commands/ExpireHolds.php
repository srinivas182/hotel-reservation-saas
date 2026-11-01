<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Availability\HoldService;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('holds:expire')]
#[Description('Mark lapsed room holds as expired in every hotel')]
final class ExpireHolds extends Command
{
    public function handle(TenantContext $context, HoldService $holds): int
    {
        $total = 0;
        $context->eachHotel(function () use ($holds, &$total): void {
            $total += $holds->expireDue();
        });

        $this->components->info("{$total} hold(s) expired.");

        return self::SUCCESS;
    }
}
