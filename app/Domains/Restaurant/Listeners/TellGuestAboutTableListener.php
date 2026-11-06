<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Listeners;

use App\Domains\Restaurant\Jobs\TellGuestAboutTable;
use App\Domains\Restaurant\TableBookingChanged;

final class TellGuestAboutTableListener
{
    public function handle(TableBookingChanged $event): void
    {
        // A short delay lets the assistant's own reply arrive first.
        TellGuestAboutTable::dispatch($event->bookingId)->delay(now()->addSeconds(5));
    }
}
