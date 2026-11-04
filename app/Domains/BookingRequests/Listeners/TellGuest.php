<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests\Listeners;

use App\Domains\BookingRequests\BookingRequestDecided;
use App\Domains\BookingRequests\Jobs\TellGuestTheDecision;

final class TellGuest
{
    public function handle(BookingRequestDecided $event): void
    {
        TellGuestTheDecision::dispatch($event->requestId);
    }
}
