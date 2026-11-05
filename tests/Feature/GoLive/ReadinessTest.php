<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\GoLive\Readiness;
use App\Domains\GoLive\ReadinessCheck;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-16 10:00:00');
    ['hotel' => $this->hotel] = assistantHotel();
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->failing = fn (): array => ($this->in)(fn () => array_values(array_map(
        fn (ReadinessCheck $c): string => $c->key,
        array_filter(app(Readiness::class)->checks(), fn (ReadinessCheck $c): bool => $c->required && ! $c->ok),
    )));
});

function goLiveSettingsPayload(string $mode): array
{
    return ['mode' => $mode, 'tone' => 'formal', 'languages' => ['fr'], 'daily_budget' => 5, 'kill_switch' => false,
        'request_hold_minutes' => 120, 'deposit_required' => false, 'deposit_percent' => 30, 'deposit_hours' => 24];
}

it('lists what is missing before go-live', function (): void {
    expect(($this->failing)())->toBe(['whatsapp_connected', 'webhook_recent', 'templates_approved', 'ai_models', 'staffed_hours', 'staff_alerts', 'background_jobs', 'backup_recent']);

    makeReady($this->hotel, $this->owner);

    expect(($this->failing)())->toBe([])
        ->and(($this->in)(fn () => app(Readiness::class)->requiredMet()))->toBeTrue();
});

it('requires a deposit-request template and instructions when deposits are on', function (): void {
    makeReady($this->hotel, $this->owner);
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['deposit_required' => true]));

    expect(($this->failing)())->toBe(['templates_approved', 'deposit_instructions']);
});

it('blocks switching the assistant on until the required checks pass', function (): void {
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.assistant.update'), goLiveSettingsPayload('supervised'))->assertSessionHasErrors('mode');
    expect(($this->in)(fn () => HotelAssistantSettings::current()->mode))->toBe(AssistantMode::Off);

    makeReady($this->hotel, $this->owner);
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.assistant.update'), goLiveSettingsPayload('supervised'))->assertSessionHasNoErrors();

    $settings = ($this->in)(fn () => HotelAssistantSettings::current());
    expect($settings->mode)->toBe(AssistantMode::Supervised)->and($settings->enabled)->toBeTrue();
});

it('lets a super admin allow go-live with a reason, audited', function (): void {
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create();
    $support = User::factory()->withTwoFactor()->create(['platform_role' => 'support']);

    $this->actingAs($support)->post(route('platform.readiness.override', $this->hotel), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($admin)->post(route('platform.readiness.override', $this->hotel), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($admin)->post(route('platform.readiness.override', $this->hotel), ['reason' => 'Backups verified manually on the old server'])->assertRedirect();

    expect(AuditLog::query()->where('event', 'go_live.override')->exists())->toBeTrue();

    $this->flushSession();
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.assistant.update'), goLiveSettingsPayload('automatic'))->assertSessionHasNoErrors();

    $this->flushSession();
    $this->actingAs($admin)->get(route('platform.readiness'))->assertInertia(fn ($page) => $page->where('hotels.0.overridden', true));
});

it('shows the go-live page to owners and managers', function (): void {
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.go-live'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ready', false)->has('checks'));

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->get(route('hotel.go-live'))->assertForbidden();
});
