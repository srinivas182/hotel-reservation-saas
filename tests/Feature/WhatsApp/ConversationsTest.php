<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Bookings\Models\Guest;
use App\Domains\Conversations\Jobs\SendMessage;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\MessageStatus;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Graph\GraphException;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Storage::fake('local');
    $this->travelTo('2026-11-12 10:00:00');
    $this->hotel = Hotel::factory()->create();
    $this->context = app(TenantContext::class);
    $this->in = fn (callable $callback) => $this->context->run($this->hotel, $callback);
    $this->account = ($this->in)(fn () => WhatsAppAccount::query()->create([
        'waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 'hotel-token',
    ]));
    // Tests change what Meta answers to a send by replacing $this->sendResponse.
    $this->sendResponse = ['body' => ['messages' => [['id' => 'wamid.OUT1']]], 'status' => 200];
    Http::fake([
        'graph.facebook.com/v23.0/5550001/messages' => fn () => Http::response($this->sendResponse['body'], $this->sendResponse['status']),
        'graph.facebook.com/v23.0/media-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/media-1', 'mime_type' => 'audio/ogg; codecs=opus', 'file_size' => 4]),
        'lookaside.fbsbx.com/*' => Http::response('OGGS', 200, ['Content-Type' => 'audio/ogg']),
    ]);
});

it('turns a guest message into a conversation linked to a guest, with the text encrypted', function (): void {
    webhookPost($this, [inbound('wamid.IN1')])->assertOk();

    $conversation = ($this->in)(fn () => Conversation::query()->with('contact.guest')->sole());
    $message = ($this->in)(fn () => Message::query()->sole());

    expect($conversation->unread_count)->toBe(1)
        ->and($conversation->windowOpen())->toBeTrue()
        ->and($conversation->contact->guest->phone_e164)->toBe('+2250700000001')
        ->and($conversation->contact->guest->first_name)->toBe('Awa')
        ->and($message->body)->toBe('Bonjour, une chambre ?')
        ->and($message->source)->toBe(MessageSource::Guest)
        ->and(DB::table('messages')->value('body'))->not->toContain('Bonjour');
});

it('links WhatsApp to the guest who already has bookings', function (): void {
    $guest = ($this->in)(fn () => Guest::query()->create(['first_name' => 'Moussa', 'phone_e164' => '+2250700000001']));

    webhookPost($this, [inbound('wamid.IN1')]);

    expect(($this->in)(fn () => Conversation::query()->with('contact')->sole()->contact->guest_id))->toBe($guest->id)
        ->and(($this->in)(fn () => Guest::query()->count()))->toBe(1);
});

it('stores a message delivered twice only once', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    webhookPost($this, [inbound('wamid.IN1')]);

    expect(($this->in)(fn () => Message::query()->count()))->toBe(1)
        ->and(($this->in)(fn () => Conversation::query()->sole()->unread_count))->toBe(1);
});

it('never moves a status backwards and records failures', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    $out = ($this->in)(fn () => Message::query()->create([
        'conversation_id' => $conversation->id, 'direction' => 'out', 'wamid' => 'wamid.OUT9', 'type' => 'text', 'body' => 'Oui',
        'status' => MessageStatus::Accepted, 'source' => MessageSource::Staff, 'sent_at' => now(),
    ]));

    webhookPost($this, [statusChange('wamid.OUT9', 'read')]);
    webhookPost($this, [statusChange('wamid.OUT9', 'delivered')]);
    webhookPost($this, [statusChange('wamid.OUT9', 'failed', ['errors' => [['code' => 131026, 'title' => 'Undeliverable']]])]);

    expect(($this->in)(fn () => $out->fresh()->status))->toBe(MessageStatus::Read);

    $second = ($this->in)(fn () => Message::query()->create([
        'conversation_id' => $conversation->id, 'direction' => 'out', 'wamid' => 'wamid.OUT10', 'type' => 'text', 'body' => 'Oui',
        'status' => MessageStatus::Accepted, 'source' => MessageSource::Staff, 'sent_at' => now(),
    ]));
    webhookPost($this, [statusChange('wamid.OUT10', 'sent')]);
    webhookPost($this, [statusChange('wamid.OUT10', 'failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]])]);

    $second = ($this->in)(fn () => $second->fresh());
    expect($second->status)->toBe(MessageStatus::Failed)
        ->and($second->error_code)->toBe(131026)
        ->and($second->error_text)->toBe('Message undeliverable');
});

it('saves voice notes privately for the hotel and lets staff play them', function (): void {
    webhookPost($this, [inbound('wamid.IN2', message: ['type' => 'audio', 'audio' => ['id' => 'media-1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]])]);

    $message = ($this->in)(fn () => Message::query()->sole());
    expect($message->media['path'])->toBe("whatsapp/{$this->hotel->id}/{$message->id}.ogg");
    Storage::disk('local')->assertExists($message->media['path']);

    $reception = staffMember($this->hotel, SystemRole::Reception);
    asStaffOf($this->hotel, $reception)->get(route('hotel.inbox.media', $message))->assertOk()->assertHeader('Content-Type', 'audio/ogg; codecs=opus');

    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->get(route('hotel.inbox.media', $message))->assertNotFound();
});

it('records media that is too large instead of downloading it', function (): void {
    Http::fake(['graph.facebook.com/v23.0/media-big' => Http::response(['url' => 'https://lookaside.fbsbx.com/big', 'mime_type' => 'video/mp4', 'file_size' => 50_000_000])]);

    webhookPost($this, [inbound('wamid.IN3', message: ['type' => 'video', 'video' => ['id' => 'media-big', 'mime_type' => 'video/mp4']])]);

    $message = ($this->in)(fn () => Message::query()->sole());
    expect($message->media['path'] ?? null)->toBeNull()->and($message->media['error'])->toContain('larger');
});

it('sends a staff reply within the 24-hour window', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->post(route('hotel.inbox.reply', $conversation), ['body' => 'Oui, nous avons des chambres.'])->assertSessionHasNoErrors();

    $reply = ($this->in)(fn () => Message::query()->where('direction', 'out')->sole());
    expect($reply->wamid)->toBe('wamid.OUT1')
        ->and($reply->status)->toBe(MessageStatus::Accepted)
        ->and($reply->sent_by)->toBe($reception->id);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/5550001/messages')
        && $request['to'] === '2250700000001'
        && $request['text']['body'] === 'Oui, nous avons des chambres.'
        && $request->hasHeader('Authorization', 'Bearer hotel-token'));
});

it('refuses free text once the 24-hour window has closed', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    $this->travel(25)->hours();

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))
        ->post(route('hotel.inbox.reply', $conversation), ['body' => 'Encore là ?'])
        ->assertSessionHasErrors('body');

    expect(($this->in)(fn () => Message::query()->where('direction', 'out')->count()))->toBe(0);
});

it('never sends the same message twice, explains Meta errors and retries server errors', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    $make = fn () => ($this->in)(fn () => Message::query()->create([
        'conversation_id' => $conversation->id, 'direction' => 'out', 'client_id' => (string) Str::uuid(), 'type' => 'text', 'body' => 'Test',
        'status' => MessageStatus::Queued, 'source' => MessageSource::Staff, 'sent_at' => now(),
    ]));
    $run = fn (Message $message) => ($this->in)(fn () => app()->call([new SendMessage($message->id), 'handle']));

    // Idempotent: a second run of the same job does not call Meta again.
    $first = $make();
    $run($first);
    $run($first);
    Http::assertSentCount(1);

    // Meta rejects the message: failed at once with a plain explanation.
    $this->sendResponse = ['body' => ['error' => ['message' => 'Re-engagement message', 'code' => 131047]], 'status' => 400];
    $closed = $make();
    $run($closed);
    $closed = ($this->in)(fn () => $closed->fresh());
    expect($closed->status)->toBe(MessageStatus::Failed)->and($closed->error_text)->toContain('24 hours');
});

it('retries when Meta has a server problem', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    $this->sendResponse = ['body' => ['error' => ['message' => 'Service unavailable', 'code' => 2]], 'status' => 503];
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    $message = ($this->in)(fn () => Message::query()->create([
        'conversation_id' => $conversation->id, 'direction' => 'out', 'client_id' => (string) Str::uuid(), 'type' => 'text', 'body' => 'Test',
        'status' => MessageStatus::Queued, 'source' => MessageSource::Staff, 'sent_at' => now(),
    ]));

    expect(fn () => ($this->in)(fn () => app()->call([new SendMessage($message->id), 'handle'])))->toThrow(GraphException::class);
    expect(($this->in)(fn () => $message->fresh()->status))->toBe(MessageStatus::Queued);
});

it('marks the account for reconnection when sending finds the token revoked', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    $this->sendResponse = ['body' => ['error' => ['message' => 'Token expired', 'code' => 190]], 'status' => 401];
    $conversation = ($this->in)(fn () => Conversation::query()->sole());

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->post(route('hotel.inbox.reply', $conversation), ['body' => 'Bonjour']);

    expect(($this->in)(fn () => $this->account->fresh()->status))->toBe(AccountStatus::ReconnectRequired)
        ->and(($this->in)(fn () => Message::query()->where('direction', 'out')->sole()->status))->toBe(MessageStatus::Failed);
});

it('shows messages sent from the WhatsApp Business app on shared numbers', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    webhookPost($this, [['field' => 'smb_message_echoes', 'value' => [
        'metadata' => ['phone_number_id' => '5550001'],
        'message_echoes' => [['from' => '2250500000000', 'to' => '2250700000001', 'id' => 'wamid.ECHO1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Réponse depuis le téléphone']]],
    ]]]);

    $echo = ($this->in)(fn () => Message::query()->where('wamid', 'wamid.ECHO1')->sole());
    expect($echo->direction)->toBe('out')
        ->and($echo->source)->toBe(MessageSource::BusinessApp)
        ->and($echo->body)->toBe('Réponse depuis le téléphone');
});

it('shows the inbox, marks a conversation read when opened and keeps hotels apart', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->get(route('hotel.inbox'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('conversations', 1)->where('conversations.0.unread', 1)->where('inboxUnread', 1));

    asStaffOf($this->hotel, $reception)->get(route('hotel.inbox.show', $conversation))
        ->assertInertia(fn ($page) => $page->where('conversation.messages.0.body', 'Bonjour, une chambre ?')->where('conversation.window_open', true));
    expect(($this->in)(fn () => $conversation->fresh()->unread_count))->toBe(0);

    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->get(route('hotel.inbox.show', $conversation))->assertNotFound();
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))->post(route('hotel.inbox.reply', $conversation), ['body' => 'x'])->assertForbidden();
});

it('processes message events that arrived before message handling existed', function (): void {
    $event = WebhookEvent::query()->create([
        'received_at' => now(), 'phone_number_id' => '5550001', 'waba_id' => '9000', 'kind' => 'messages',
        'payload' => inbound('wamid.OLD1')['value'],
    ]);

    $this->artisan('whatsapp:replay')->assertSuccessful();

    expect($event->fresh()->processed_at)->not->toBeNull()
        ->and(($this->in)(fn () => Message::query()->where('wamid', 'wamid.OLD1')->exists()))->toBeTrue();
});
