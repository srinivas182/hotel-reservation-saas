<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Access\HotelRoles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('permissions:sync')]
#[Description('Create permissions and update every hotel\'s system roles')]
final class SyncPermissions extends Command
{
    public function handle(HotelRoles $roles): int
    {
        $roles->sync();

        $this->components->info('Permissions and system roles are up to date.');

        return self::SUCCESS;
    }
}
