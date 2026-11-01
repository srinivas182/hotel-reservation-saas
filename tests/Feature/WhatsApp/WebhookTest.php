<?php

declare(strict_types=1);

use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Webhooks\ProcessWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    configureMeta();
    $this->hotel = Hotel::factory()->create();
    $this->context = app(TenantContext::class);
    $this->account = $this->context->run($this->hotel, fn () => WhatsAppAccount::query()->create([
        'waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 'x', 'messaging_limit' => 'TIER_1K',
    ]));
});

function qualityChange(string $phoneNumberId = '5550001', string $limit = 'TIER_10K'): array
{
    return ['field' => 'phone_number_quality_update', 'value' => ['display_phone_number' => '2250700000000', 'event' => 'FLAGGED', 'current_limit' => $limit, 'metadata' => ['phone_number_id' => $phoneNumberId]]];
}

it('answers Meta\'s verification request only with the right token', function (): void {
    $this->get(route('webhooks.whatsapp.verify', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-me', 'hub.challenge' => '12345']))
        ->assertOk()
        ->assertSeeText('12345');

    $this->get(route('webhooks.whatsapp.verify', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'wrong', 'hub.challenge' => '12345']))
        ->assertForbidden();
});

it('rejects deliveries with a missing or wrong signature', function (): void {
    Queue::fake();

    webhookPost($this, [qualityChange()], signature: 'sha256=forged')->assertUnauthorized();
    $this->call('POST', route('webhooks.whatsapp.receive'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertUnauthorized();

    expect(WebhookEvent::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('stores each change encrypted, answers at once and processes in the background', function (): void {
    Queue::fake();

    webhookPost($this, [qualityChange(), qualityChange()])->assertOk()->assertSeeText('EVENT_RECEIVED');

    expect(WebhookEvent::query()->count())->toBe(2)
        ->and(DB::table('whatsapp_webhook_events')->value('payload'))->not->toContain('TIER_10K');
    Queue::assertPushed(ProcessWebhookEvent::class, 2);
});

it('routes events to the hotel that owns the number', function (): void {
    webhookPost($this, [qualityChange(limit: 'TIER_10K')])->assertOk();

    $account = $this->context->run($this->hotel, fn () => $this->account->fresh());
    expect($account->messaging_limit)->toBe('TIER_10K')
        ->and(WebhookEvent::query()->sole()->processed_at)->not->toBeNull();
});

it('closes events for numbers no hotel has connected', function (): void {
    webhookPost($this, [qualityChange('9999999')], wabaId: '1234')->assertOk();

    $event = WebhookEvent::query()->sole();
    expect($event->processed_at)->not->toBeNull()->and($event->error)->toBe('No hotel has this number.');
});

it('keeps events without a handler yet for replay', function (): void {
    webhookPost($this, [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '5550001'], 'messages' => []]]])->assertOk();

    expect(WebhookEvent::query()->sole()->processed_at)->toBeNull();
});

it('marks the account restricted when Meta restricts it', function (): void {
    webhookPost($this, [['field' => 'account_update', 'value' => ['event' => 'ACCOUNT_RESTRICTION']]])->assertOk();

    expect($this->context->run($this->hotel, fn () => $this->account->fresh()->status))->toBe(AccountStatus::Restricted);
});

it('deletes stored webhook events after 30 days', function (): void {
    webhookPost($this, [qualityChange()]);
    $this->travel(31)->days();

    $this->artisan('model:prune', ['--model' => [WebhookEvent::class]])->assertSuccessful();

    expect(WebhookEvent::query()->count())->toBe(0);
});
