<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:expire-tentative')]
#[Description('Cancel tentative bookings whose deadline has passed, in every hotel')]
final class ExpireTentativeBookings extends Command
{
    public function handle(TenantContext $context, BookingLifecycle $lifecycle): int
    {
        $total = 0;
        $context->eachHotel(function () use ($lifecycle, &$total): void {
            $total += $lifecycle->expireTentative();
        });

        $this->components->info("{$total} tentative booking(s) expired.");

        return self::SUCCESS;
    }
}
