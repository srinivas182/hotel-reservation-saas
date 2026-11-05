<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Operations\BackupRun;
use App\Domains\Operations\OpsIncident;
use App\Domains\Operations\PilotMetrics;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pilot success measures per hotel, with open operations problems and the
 * latest backup and restore drill.
 */
final class PilotDashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): Response
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'in:7,14,30']]);
        $days = (int) ($data['days'] ?? 7);
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);

        $hotels = Hotel::query()->orderBy('name')->get()->map(fn (Hotel $hotel): array => [
            'id' => $hotel->id,
            'name' => $hotel->name,
            'metrics' => $context->run($hotel, fn (): array => app(PilotMetrics::class)->measure($from, $to)),
        ])->values();

        $backup = BackupRun::last('backup');
        $drill = BackupRun::last('restore_drill');

        return Inertia::render('platform/pilot', [
            'days' => $days,
            'hotels' => $hotels,
            'incidents' => OpsIncident::query()->whereNull('resolved_at')->orderBy('opened_at')->get()->map(fn (OpsIncident $i): array => [
                'title' => $i->title,
                'detail' => $i->detail,
                'since' => $i->opened_at->toDateTimeString(),
            ])->values(),
            'backup' => $backup === null ? null : ['status' => $backup->status, 'at' => $backup->created_at?->toDateTimeString(), 'off_site' => $backup->off_site],
            'drill' => $drill === null ? null : ['status' => $drill->status, 'at' => $drill->created_at?->toDateTimeString()],
        ]);
    }
}
