<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\StaffActions\Models\StaffAction;
use App\Domains\StaffActions\StaffActionRouter;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('staff-actions:run-due')]
#[Description('Carry out WhatsApp staff decisions whose undo minute passed but whose job did not run, in every hotel')]
final class RunDueStaffActions extends Command
{
    public function handle(TenantContext $context, StaffActionRouter $router): int
    {
        $total = 0;
        $context->eachHotel(function () use ($router, &$total): void {
            // Give the queued job a head start; only late ones are swept.
            foreach (StaffAction::query()->where('status', 'scheduled')->where('execute_at', '<=', now()->subSeconds(30))->pluck('id') as $id) {
                $router->execute((string) $id);
                $total++;
            }
        });

        $this->components->info("{$total} staff decision(s) carried out.");

        return self::SUCCESS;
    }
}
