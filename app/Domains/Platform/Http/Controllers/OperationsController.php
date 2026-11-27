<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Operations\BackupRun;
use App\Domains\Operations\OpsCheck;
use App\Domains\Operations\OpsIncident;
use App\Domains\Platform\Health\HeartbeatCheck;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the ops alert emails say, in one place, with the common fixes.
 */
final class OperationsController extends Controller
{
    public function index(OpsCheck $check): Response
    {
        $hotels = Hotel::query()->pluck('name', 'id');

        // 0.39.4: things that are not alerts but must not hide behind
        // "All checks pass" (testers: no backup and failed jobs were shown
        // under a green banner).
        $warnings = [];
        if (! config('ops.backups_enabled')) {
            $warnings[] = ['key' => 'backups_not_checked', 'detail' => (string) __('Backups are not checked on this server (OPS_BACKUPS_ENABLED is off).')];
        }
        if (BackupRun::lastSuccess('backup') === null) {
            $warnings[] = ['key' => 'no_backup', 'detail' => (string) __('No successful backup has been recorded yet.')];
        }
        $failedJobs = DB::table('failed_jobs')->count();
        if ($failedJobs > 0) {
            $warnings[] = ['key' => 'failed_jobs', 'detail' => (string) __(':count failed background job(s): retry or discard them below.', ['count' => $failedJobs])];
        }

        return Inertia::render('platform/operations', [
            'warnings' => $warnings,
            'problems' => collect($check->problems())->map(fn (string $detail, string $key): array => ['key' => $key, 'detail' => $detail])->values(),
            'incidents' => OpsIncident::query()->latest('opened_at')->limit(20)->get()->map(fn (OpsIncident $i): array => [
                'id' => $i->id, 'title' => $i->title, 'opened' => $i->opened_at->toDateTimeString(), 'resolved' => $i->resolved_at?->toDateTimeString(),
            ])->values(),
            'heartbeats' => collect([HeartbeatCheck::scheduler(), HeartbeatCheck::queue()])->map(fn (HeartbeatCheck $h): array => ['name' => $h->name(), ...$h->run()])->values(),
            'waiting' => $check->queueSize(),
            'failed' => DB::table('failed_jobs')->latest('failed_at')->limit(50)->get()->map(fn ($f): array => [
                'uuid' => (string) $f->uuid, 'queue' => (string) $f->queue, 'job' => (string) (json_decode((string) $f->payload, true)['displayName'] ?? ''),
                'error' => Str::limit(strtok((string) $f->exception, "\n") ?: '', 200), 'failed_at' => (string) $f->failed_at,
            ])->values(),
            'backups' => BackupRun::query()->latest('id')->limit(5)->get()->map(fn (BackupRun $b): array => ['kind' => $b->kind, 'status' => $b->status, 'at' => $b->created_at?->toDateTimeString(), 'off_site' => $b->off_site, 'size' => $b->size_bytes])->values(),
            'whatsapp' => WhatsAppAccount::query()->withoutGlobalScopes()->orderBy('status')->get()->map(fn (WhatsAppAccount $a): array => [
                'hotel' => $hotels[$a->hotel_id] ?? $a->hotel_id, 'hotel_id' => $a->hotel_id, 'phone' => $a->display_phone, 'status' => $a->status->value,
                'quality' => $a->quality_rating, 'limit' => $a->messaging_limit, 'error' => $a->last_error, 'checked' => $a->token_checked_at?->toDateTimeString(),
            ])->values(),
        ]);
    }

    public function retry(string $uuid, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Audit::record('ops.job_retried', 'Failed job retried', causer: $user, properties: ['uuid' => $uuid]);

        return back();
    }

    public function forget(string $uuid, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:forget', ['id' => $uuid]);
        Audit::record('ops.job_discarded', 'Failed job discarded', causer: $user, properties: ['uuid' => $uuid]);

        return back();
    }
}
