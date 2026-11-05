<?php

declare(strict_types=1);

use App\Domains\Ai\AiTask;
use App\Domains\Ai\Models\AiRun;
use App\Domains\Operations\BackupRun;
use App\Domains\Operations\Mail\OpsAlertMail;
use App\Domains\Operations\OpsCheck;
use App\Domains\Operations\OpsIncident;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    config(['ops.alert_email' => 'ops@mcs.example, oncall@mcs.example']);
    $this->travelTo('2026-11-16 10:00:00');
    Cache::put('health:heartbeat:scheduler', now()->getTimestamp());
    Cache::put('health:heartbeat:queue', now()->getTimestamp());
    $this->check = fn (): array => app(OpsCheck::class)->problems();
});

it('reports nothing when the platform is healthy', function (): void {
    expect(($this->check)())->toBe([]);
});

it('emails once when a problem starts, every two hours while it lasts, and when it clears', function (): void {
    Cache::forget('health:heartbeat:scheduler');

    $this->artisan('platform:ops-check');
    $this->travel(30)->minutes();
    Cache::put('health:heartbeat:queue', now()->getTimestamp());
    $this->artisan('platform:ops-check');
    Mail::assertSent(OpsAlertMail::class, 1);
    Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $m): bool => $m->state === 'opened' && $m->hasTo('ops@mcs.example') && $m->hasTo('oncall@mcs.example'));

    $this->travel(2)->hours();
    Cache::put('health:heartbeat:queue', now()->getTimestamp());
    $this->artisan('platform:ops-check');
    Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $m): bool => $m->state === 'reminder');

    Cache::put('health:heartbeat:scheduler', now()->getTimestamp());
    $this->artisan('platform:ops-check');
    Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $m): bool => $m->state === 'resolved');
    expect(OpsIncident::query()->sole()->resolved_at)->not->toBeNull();
});

it('notices WhatsApp silence in the daytime and failed notifications', function (): void {
    $hotel = Hotel::factory()->create();
    app(TenantContext::class)->run($hotel, fn () => WhatsAppAccount::query()->create(['waba_id' => '9', 'phone_number_id' => '5', 'status' => AccountStatus::Connected, 'token' => 't']));

    expect(($this->check)())->toHaveKey('webhook_silence');

    $this->travelTo('2026-11-16 23:00:00');
    Cache::put('health:heartbeat:scheduler', now()->getTimestamp());
    Cache::put('health:heartbeat:queue', now()->getTimestamp());
    expect(($this->check)())->not->toHaveKey('webhook_silence');

    WebhookEvent::query()->create(['received_at' => now(), 'phone_number_id' => '5', 'kind' => 'messages', 'payload' => [], 'attempts' => 5, 'error' => 'boom']);
    expect(($this->check)())->toHaveKey('webhook_failures');
});

it('notices AI providers failing', function (): void {
    $hotel = Hotel::factory()->create();
    app(TenantContext::class)->run($hotel, function (): void {
        foreach (range(1, 6) as $i) {
            AiRun::query()->create(['task' => AiTask::Assistant, 'outcome' => $i <= 5 ? 'error' : 'ok']);
        }
    });

    expect(($this->check)())->toHaveKey('ai_failures');
});

it('notices missing, failed and unverified backups when backups are enabled', function (): void {
    config(['ops.backups_enabled' => true]);
    expect(($this->check)())->toHaveKeys(['backup_missing', 'restore_drill']);

    $this->artisan('backup:record', ['status' => 'success', '--label' => 'nightly', '--file' => 'x.sql.gz.enc', '--size' => '1024', '--off-site' => true])->assertSuccessful();
    BackupRun::query()->create(['kind' => 'restore_drill', 'status' => 'success']);
    expect(($this->check)())->toBe([]);

    $this->artisan('backup:record', ['status' => 'failure', '--error' => 'disk full']);
    expect(($this->check)())->toHaveKey('backup_failed');
});
