<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\Bookings\Models\Guest;
use App\Domains\Conversations\ConversationMode;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Hotel\StaffedHours;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Transcription;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Storage::fake('local');
    $this->travelTo('2026-11-12 10:00:00');
    ['hotel' => $this->hotel] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(function (): void {
        WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 'hotel-token']);
        HotelAssistantSettings::current()->update(['enabled' => true]);
    });
    $this->sent = 0;
    Http::fake([
        'graph.facebook.com/v23.0/5550001/messages' => function () {
            $this->sent++;

            return Http::response(['messages' => [['id' => 'wamid.OUT'.$this->sent]]]);
        },
        'graph.facebook.com/v23.0/media-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/media-1', 'mime_type' => 'audio/ogg', 'file_size' => 4]),
        'lookaside.fbsbx.com/*' => Http::response('OGGS', 200, ['Content-Type' => 'audio/ogg']),
    ]);
    Queue::fake([ReplyToGuest::class]);
});

/**
 * Run the reply jobs queued so far, in order, then forget them.
 */
function runReplies(object $test): void
{
    $jobs = Queue::pushed(ReplyToGuest::class)->all();
    Queue::fake([ReplyToGuest::class]);

    foreach ($jobs as $job) {
        ($test->in)(fn () => app()->call([$job, 'handle']));
    }
}

function assistantMessages(object $test): array
{
    // Without the "virtual assistant" line added to the first reply (tested separately).
    return ($test->in)(fn () => Message::query()->where('source', MessageSource::Assistant->value)->orderBy('sent_at')->orderBy('id')->pluck('body')
        ->map(fn (?string $body): string => explode("\n\n", (string) $body)[0])->all());
}

it('answers a guest message once, 8 seconds later', function (): void {
    HotelAssistantAgent::fake([aiReply('Bonjour ! Comment puis-je vous aider ?', ['intent' => 'greeting'])]);

    webhookPost($this, [inbound('wamid.IN1', message: ['text' => ['body' => 'Bonjour']])]);

    Queue::assertPushed(ReplyToGuest::class, fn (ReplyToGuest $job): bool => $job->delay !== null);
    runReplies($this);

    expect(assistantMessages($this))->toBe(['Bonjour ! Comment puis-je vous aider ?']);

    // The same webhook again creates no job and no second reply.
    webhookPost($this, [inbound('wamid.IN1', message: ['text' => ['body' => 'Bonjour']])]);
    runReplies($this);
    expect(assistantMessages($this))->toHaveCount(1);
});

it('answers a burst of messages with one reply', function (): void {
    HotelAssistantAgent::fake([aiReply('Oui, pour quelles dates ?', ['intent' => 'availability_question'])]);

    webhookPost($this, [inbound('wamid.IN1', message: ['text' => ['body' => 'Bonjour']])]);
    $this->travel(3)->seconds();
    webhookPost($this, [inbound('wamid.IN2', message: ['text' => ['body' => 'Vous avez une chambre ?']])]);
    runReplies($this);

    expect(assistantMessages($this))->toBe(['Oui, pour quelles dates ?']);
    HotelAssistantAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, "Bonjour\nVous avez une chambre ?"));
});

it('stays silent in staff or paused mode, after a staff reply, when switched off, and for old messages', function (): void {
    HotelAssistantAgent::fake();
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());

    ($this->in)(fn () => $conversation->update(['mode' => ConversationMode::Staff]));
    runReplies($this);

    ($this->in)(fn () => $conversation->update(['mode' => ConversationMode::Assistant]));
    ($this->in)(fn () => $conversation->forceFill(['staff_replied_at' => now()->subMinutes(5)])->save());
    webhookPost($this, [inbound('wamid.IN2')]);
    runReplies($this);

    ($this->in)(fn () => $conversation->forceFill(['staff_replied_at' => null])->save());
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['enabled' => false]));
    webhookPost($this, [inbound('wamid.IN3')]);
    runReplies($this);

    ($this->in)(fn () => HotelAssistantSettings::current()->update(['enabled' => true]));
    webhookPost($this, [inbound('wamid.IN4', timestamp: now()->subHours(30)->timestamp)]);
    runReplies($this);

    HotelAssistantAgent::assertNeverPrompted();
    expect(assistantMessages($this))->toBe([]);
});

it('treats replies sent from the WhatsApp Business app as staff replies', function (): void {
    HotelAssistantAgent::fake();
    webhookPost($this, [inbound('wamid.IN1')]);
    webhookPost($this, [['field' => 'smb_message_echoes', 'value' => [
        'metadata' => ['phone_number_id' => '5550001'],
        'message_echoes' => [['from' => '2250500000000', 'to' => '2250700000001', 'id' => 'wamid.ECHO', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Je m’en occupe']]],
    ]]]);
    runReplies($this);

    HotelAssistantAgent::assertNeverPrompted();
});

it('passes the conversation to staff with the hotel\'s message and hands it back', function (): void {
    HotelAssistantAgent::fake([
        aiReply('Je suis désolé. Je transmets à notre équipe.', ['intent' => 'complaint']),
        aiReply('Bien sûr, pour quelles dates ?', ['intent' => 'availability_question']),
    ]);
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['handover_messages' => ['fr' => 'La réception vous répond tout de suite.']]));

    webhookPost($this, [inbound('wamid.IN1', message: ['text' => ['body' => 'La clim ne marche pas, c’est inadmissible']])]);
    runReplies($this);

    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    expect($conversation->mode)->toBe(ConversationMode::Staff)
        ->and($conversation->needs_attention)->toBeTrue()
        ->and($conversation->handover_reason)->toBe('complaint')
        ->and(assistantMessages($this))->toBe(['Je suis désolé. Je transmets à notre équipe.']);

    $reception = staffMember($this->hotel, SystemRole::Reception);
    asStaffOf($this->hotel, $reception)->get(route('hotel.inbox', ['filter' => 'attention']))->assertInertia(fn ($page) => $page->has('conversations', 1));

    asStaffOf($this->hotel, $reception)->put(route('hotel.inbox.mode', $conversation), ['mode' => 'assistant'])->assertRedirect();
    expect(($this->in)(fn () => $conversation->fresh()->mode))->toBe(ConversationMode::Assistant);

    // The complaint is not answered again; the next message is.
    webhookPost($this, [inbound('wamid.IN2', message: ['text' => ['body' => 'Et une chambre pour demain ?']])]);
    runReplies($this);
    expect(assistantMessages($this))->toHaveCount(2);
});

it('answers voice notes once transcribed and passes them to staff when they cannot be understood', function (): void {
    $provider = AiProvider::query()->create(['name' => 'OpenAI', 'driver' => 'openai', 'credentials' => ['key' => 'k'], 'active' => true]);
    $model = AiModel::query()->create(['ai_provider_id' => $provider->id, 'model' => 'transcribe', 'label' => 'T', 'capabilities' => ['transcription'], 'status' => 'approved']);
    AiPlatformSettings::current()->update(['transcription_model_id' => $model->id]);
    Transcription::fake(['Bonjour, une chambre pour ce soir ?']);
    HotelAssistantAgent::fake([aiReply('Oui, nous avons des chambres ce soir.', ['intent' => 'availability_question'])]);

    webhookPost($this, [inbound('wamid.V1', message: ['type' => 'audio', 'audio' => ['id' => 'media-1', 'mime_type' => 'audio/ogg', 'voice' => true]])]);
    runReplies($this);

    expect(assistantMessages($this))->toBe(['Oui, nous avons des chambres ce soir.']);
    HotelAssistantAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '[Voice note] Bonjour, une chambre pour ce soir ?'));

    Transcription::fake(['']);
    webhookPost($this, [inbound('wamid.V2', message: ['type' => 'audio', 'audio' => ['id' => 'media-1', 'mime_type' => 'audio/ogg', 'voice' => true]])]);
    runReplies($this);

    expect(($this->in)(fn () => Conversation::query()->sole()->handover_reason))->toBe('voice_note_not_understood');
})->skip(fn (): bool => ! class_exists(Transcription::class));

it('passes the conversation to staff after too many replies or repeated replies', function (string $setup, string $reason): void {
    HotelAssistantAgent::fake([aiReply('Bonjour !', ['intent' => 'greeting'])]);
    webhookPost($this, [inbound('wamid.IN0')]);
    runReplies($this);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());

    ($this->in)(function () use ($conversation, $setup): void {
        $count = $setup === 'limit' ? 30 : 5;

        foreach (range(1, $count) as $i) {
            Message::query()->create([
                'conversation_id' => $conversation->id, 'direction' => 'out', 'type' => 'text',
                'body' => $setup === 'limit' ? "Réponse {$i}" : 'Pouvez-vous préciser vos dates ?',
                'status' => 'sent', 'source' => MessageSource::Assistant, 'sent_at' => now()->addSeconds($i),
            ]);
        }
    });

    $this->travel(1)->minute();
    webhookPost($this, [inbound('wamid.IN1')]);
    runReplies($this);

    expect(($this->in)(fn () => $conversation->fresh()->handover_reason))->toBe($reason);
    HotelAssistantAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Bonjour, une chambre ?'));
})->with([
    'reply limit' => ['limit', 'reply_limit'],
    'repeated replies' => ['loop', 'repeated_replies'],
]);

it('tells the assistant when reception is closed', function (): void {
    HotelAssistantAgent::fake([aiReply('La réception vous confirmera demain dès 8 h.', ['intent' => 'booking_request'])]);
    ($this->in)(fn () => HotelSettings::current()->update(['staffed_hours' => ['thu' => [['from' => '08:00', 'to' => '09:00']], 'fri' => [['from' => '08:00', 'to' => '20:00']]]]));

    webhookPost($this, [inbound('wamid.IN1')]);
    runReplies($this);

    HotelAssistantAgent::assertPrompted(fn ($prompt) => str_contains($prompt->agent->instructions(), 'Reception is closed now. Staff will reply and confirm bookings from Friday 08:00'));
});

it('works out when reception is open in the hotel\'s time zone', function (): void {
    $this->travelTo('2026-11-12 10:00:00'); // Thursday
    $hours = new StaffedHours($this->hotel, ['thu' => [['from' => '08:00', 'to' => '12:00'], ['from' => '14:00', 'to' => '18:00']], 'sat' => [['from' => '09:00', 'to' => '12:00']]]);

    expect($hours->isOpen())->toBeTrue()
        ->and($hours->isOpen(now()->toImmutable()->setTime(13, 0)))->toBeFalse()
        ->and($hours->nextOpening(now()->toImmutable()->setTime(13, 0))?->format('D H:i'))->toBe('Thu 14:00')
        ->and($hours->nextOpening(now()->toImmutable()->setTime(19, 0))?->format('D H:i'))->toBe('Sat 09:00')
        ->and((new StaffedHours($this->hotel, null))->isOpen())->toBeTrue();
});

it('records that the guest chose to contact the hotel', function (): void {
    HotelAssistantAgent::fake([aiReply('Bonjour !', ['intent' => 'greeting'])]);

    webhookPost($this, [inbound('wamid.IN1')]);

    $guest = ($this->in)(fn () => Guest::query()->sole());
    expect($guest->whatsapp_opt_in)->toBeTrue()->and($guest->marketing_opt_in)->toBeFalse();
});

it('lets only staff who can reply change the mode', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))->put(route('hotel.inbox.mode', $conversation), ['mode' => 'staff'])->assertForbidden();
});
