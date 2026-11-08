<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Platform\Lifecycle\Closures;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hotels:closures')]
#[Description('Remind owners of closed hotels before deletion, and delete data 90 days after closure')]
final class SweepHotelClosures extends Command
{
    public function handle(Closures $closures): int
    {
        $result = $closures->sweep();
        $this->components->info("{$result['reminders']} reminder(s), {$result['purged']} hotel(s) deleted.");

        return self::SUCCESS;
    }
}
