<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Operations\OpsCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:ops-check')]
#[Description('Look for platform problems and email MCS operations')]
final class OpsCheckCommand extends Command
{
    public function handle(OpsCheck $check): int
    {
        $changes = $check->run();
        $this->components->info("{$changes} notification(s).");

        return self::SUCCESS;
    }
}
