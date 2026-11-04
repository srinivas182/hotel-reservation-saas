<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('booking-requests:expire')]
#[Description('Expire WhatsApp booking requests nobody answered in time, in every hotel')]
final class ExpireBookingRequests extends Command
{
    public function handle(TenantContext $context, BookingRequestService $requests): int
    {
        $total = 0;
        $context->eachHotel(function () use ($requests, &$total): void {
            $total += $requests->expireDue();
        });

        $this->components->info("{$total} request(s) expired.");

        return self::SUCCESS;
    }
}
