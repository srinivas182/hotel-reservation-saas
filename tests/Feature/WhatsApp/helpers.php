<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

function configureMeta(): void
{
    config([
        'whatsapp.app_id' => '111111',
        'whatsapp.app_secret' => 'test-app-secret',
        'whatsapp.config_id' => '222222',
        'whatsapp.verify_token' => 'verify-me',
        'whatsapp.graph_version' => 'v23.0',
    ]);
}

/**
 * Fake Meta's Graph API for a successful connection.
 */
function fakeGraph(array $overrides = []): void
{
    Http::fake([
        'graph.facebook.com/v23.0/oauth/access_token*' => Http::response(['access_token' => 'business-token-abc'] + ($overrides['token'] ?? [])),
        'graph.facebook.com/v23.0/*/register' => Http::response(['success' => true]),
        'graph.facebook.com/v23.0/*/subscribed_apps' => Http::response(['success' => true]),
        'graph.facebook.com/v23.0/*' => $overrides['phone'] ?? Http::response([
            'display_phone_number' => '+225 07 00 00 00 00',
            'verified_name' => 'First Class Lodge',
            'quality_rating' => 'GREEN',
            'messaging_limit_tier' => 'TIER_1K',
        ]),
    ]);
}

/**
 * A signed webhook delivery, as Meta sends it.
 *
 * @param  list<array<string, mixed>>  $changes
 */
function webhookPost(Tests\TestCase $test, array $changes, string $wabaId = '9000', ?string $signature = null): Illuminate\Testing\TestResponse
{
    $body = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [['id' => $wabaId, 'changes' => $changes]],
    ], JSON_THROW_ON_ERROR);

    return $test->call('POST', route('webhooks.whatsapp.receive'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, 'test-app-secret'),
    ], $body);
}

function inbound(string $wamid, string $from = '2250700000001', array $message = [], string $name = 'Awa Koné', ?int $timestamp = null): array
{
    return ['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => '2250500000000', 'phone_number_id' => '5550001'],
        'contacts' => [['profile' => ['name' => $name], 'wa_id' => $from]],
        'messages' => [['from' => $from, 'id' => $wamid, 'timestamp' => (string) ($timestamp ?? now()->timestamp), 'type' => 'text', 'text' => ['body' => 'Bonjour, une chambre ?'], ...$message]],
    ]];
}

function statusChange(string $wamid, string $status, array $extra = []): array
{
    return ['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => '5550001'],
        'statuses' => [['id' => $wamid, 'status' => $status, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '2250700000001', ...$extra]],
    ]];
}
