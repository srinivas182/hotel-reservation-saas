<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('booking-requests:escalate')]
#[Description('Send WhatsApp booking requests nobody answered in time to the owners, in every hotel')]
final class EscalateBookingRequests extends Command
{
    public function handle(TenantContext $context, BookingRequestService $requests): int
    {
        $total = 0;
        $context->eachHotel(function () use ($requests, &$total): void {
            $total += $requests->escalateDue();
        });

        $this->components->info("{$total} request(s) escalated.");

        return self::SUCCESS;
    }
}
