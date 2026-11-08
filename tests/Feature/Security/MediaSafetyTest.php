<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Conversations\Jobs\DownloadMedia;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Conversations\Models\WhatsAppContact;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../WhatsApp/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Storage::fake('local');
    $this->hotel = Hotel::factory()->create();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 'hotel-token']));
    $this->conversation = ($this->in)(function () {
        $contact = WhatsAppContact::query()->create(['wa_id' => '2250700000001']);

        return Conversation::query()->create(['whatsapp_contact_id' => $contact->id, 'status' => 'open', 'last_message_at' => now()]);
    });
});

function mediaMessage($test, string $mediaId, string $type): Message
{
    return ($test->in)(fn () => Message::query()->create([
        'conversation_id' => $test->conversation->id, 'direction' => 'in', 'type' => $type, 'status' => 'received', 'source' => 'guest',
        'sent_at' => now(), 'media' => ['media_id' => $mediaId],
    ]));
}

it('never stores files of dangerous types', function (string $mime): void {
    Http::fake([
        'graph.facebook.com/v23.0/media-x' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => $mime, 'file_size' => 10]),
        'lookaside.fbsbx.com/*' => Http::response('<script>alert(1)</script>', 200, ['Content-Type' => $mime]),
    ]);
    $message = mediaMessage($this, 'media-x', 'document');

    ($this->in)(fn () => (new DownloadMedia($message->id))->handle(app(App\Domains\WhatsApp\Graph\GraphClient::class)));

    expect($message->fresh()->media['error'] ?? null)->toBe('refused_type')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['text/html', 'image/svg+xml', 'application/x-msdownload', 'application/javascript']);

it('serves stored documents as downloads in a sandbox', function (): void {
    Http::fake([
        'graph.facebook.com/v23.0/media-d' => Http::response(['url' => 'https://lookaside.fbsbx.com/d', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'file_size' => 4]),
        'lookaside.fbsbx.com/*' => Http::response('PK..', 200),
    ]);
    $message = mediaMessage($this, 'media-d', 'document');
    ($this->in)(fn () => (new DownloadMedia($message->id))->handle(app(App\Domains\WhatsApp\Graph\GraphClient::class)));

    $response = asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->get(route('hotel.inbox.media', $message->fresh()));
    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});
