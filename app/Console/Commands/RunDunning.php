<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Billing\Invoices\Dunning;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:dunning')]
#[Description('Send payment reminders and switch hotels with long-overdue invoices to reduced mode')]
final class RunDunning extends Command
{
    public function handle(Dunning $dunning): int
    {
        $result = $dunning->run();
        $this->components->info("{$result['reminders']} reminder(s), {$result['reduced']} hotel(s) in reduced mode.");

        return self::SUCCESS;
    }
}
