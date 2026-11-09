<?php

declare(strict_types=1);

use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Conversations\Models\WhatsAppContact;
use App\Domains\Launch\LaunchChecklist;
use App\Domains\Launch\Models\LaunchItem;
use App\Domains\Operations\Drills;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

it('records drills and completes the launch item when all pass on staging', function (): void {
    $this->artisan('drills:record', ['drill' => 'nope', 'result' => 'pass'])->assertExitCode(2);

    foreach (array_keys(Drills::REQUIRED) as $drill) {
        Drills::record($drill, true, 'staging');
    }
    Drills::record('restore', false, 'staging', 'checksum mismatch');
    expect(Drills::allPassed())->toBeFalse();

    Drills::record('restore', true, 'staging');
    app(LaunchChecklist::class)->refresh();
    expect(Drills::allPassed())->toBeTrue()->and(LaunchItem::query()->where('key', 'staging_drills')->value('status'))->toBe('done');

    $this->travel(31)->days();
    app(LaunchChecklist::class)->refresh();
    expect(LaunchItem::query()->where('key', 'staging_drills')->value('status'))->toBe('open');
});

it('counts Content Security Policy reports by directive and source', function (): void {
    $report = fn (string $uri) => json_encode(['csp-report' => ['document-uri' => 'https://app.example/hotel/today?x=1', 'effective-directive' => 'script-src-elem', 'blocked-uri' => $uri]]);

    foreach (['https://cdn.evil.example/x.js', 'https://cdn.evil.example/y.js', 'inline'] as $uri) {
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $report($uri))->assertNoContent();
    }

    expect(DB::table('csp_reports')->where('blocked', 'cdn.evil.example')->value('count'))->toBe(2)
        ->and(DB::table('csp_reports')->where('blocked', 'inline')->value('page'))->toBe('/hotel/today');
});

it('checks the simulated conversations after a drill', function (): void {
    $hotel = Hotel::factory()->create(['slug' => 'first-class-lodge']);
    $since = now()->subMinute()->toIso8601String();

    app(TenantContext::class)->run($hotel, function (): void {
        $contact = WhatsAppContact::query()->create(['wa_id' => '2250700000001']);
        $conversation = Conversation::query()->create(['whatsapp_contact_id' => $contact->id, 'status' => 'open', 'last_message_at' => now()]);
        Message::query()->create(['conversation_id' => $conversation->id, 'direction' => 'in', 'type' => 'text', 'body' => 'Bonjour', 'status' => 'received', 'source' => 'guest', 'sent_at' => now()]);
    });
    $this->artisan('simulation:check', ['--since' => $since])->assertFailed();

    app(TenantContext::class)->run($hotel, fn () => Message::query()->create(['conversation_id' => Conversation::query()->value('id'), 'direction' => 'out', 'type' => 'text', 'body' => 'Bonsoir', 'status' => 'sent', 'source' => 'assistant', 'wamid' => 'wamid.1', 'sent_at' => now()]));
    $this->artisan('simulation:check', ['--since' => $since])->assertSuccessful();
});
