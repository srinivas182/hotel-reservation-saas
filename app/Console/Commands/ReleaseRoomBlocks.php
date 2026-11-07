<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Events\RoomBlocks;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('events:release-room-blocks')]
#[Description('Record attendee room blocks past their cut-off as released, in every hotel')]
final class ReleaseRoomBlocks extends Command
{
    public function handle(TenantContext $context, RoomBlocks $blocks): int
    {
        $total = 0;
        $context->eachHotel(function () use ($blocks, &$total): void {
            $total += $blocks->releaseDue();
        });
        $this->components->info("{$total} room block(s) released.");

        return self::SUCCESS;
    }
}
