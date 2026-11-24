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

it('alerts staff about a discount question but keeps answering the guest', function (): void {
    HotelAssistantAgent::fake([
        aiReply('Le paiement immédiat donne déjà la meilleure remise. Souhaitez-vous réserver ?', ['intent' => 'price_question']),
        aiReply('Avec plaisir. À quel nom dois-je faire la demande ?', ['intent' => 'booking_request']),
    ]);

    webhookPost($this, [inbound('wamid.IN1', message: ['text' => ['body' => 'Is 10% the maximum or can I get more discount?']])]);
    runReplies($this);

    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    expect($conversation->mode)->toBe(ConversationMode::Assistant)
        ->and($conversation->needs_attention)->toBeTrue()
        ->and($conversation->handover_reason)->toBe('discount_request')
        ->and(assistantMessages($this))->toHaveCount(1);

    // The guest goes on: the assistant answers, it is not stuck with staff.
    $this->travel(1)->minutes();
    webhookPost($this, [inbound('wamid.IN2', message: ['text' => ['body' => 'Ok, help me to book the room']])]);
    runReplies($this);

    expect(assistantMessages($this))->toHaveCount(2)
        ->and(last(assistantMessages($this)))->toContain('À quel nom');
});

it('never answers or hands over a reaction, even right after a question', function (): void {
    HotelAssistantAgent::fake([aiReply('Avec plaisir, pour quelles dates ?', ['intent' => 'availability_question'])]);

    webhookPost($this, [inbound('wamid.IN1', message: ['text' => ['body' => 'Vous avez une suite ?']])]);
    $this->travel(2)->seconds();
    // The guest reacts 👍 before the reply: the question is still answered.
    webhookPost($this, [inbound('wamid.R1', message: ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.OUT1', 'emoji' => '👍']])]);
    runReplies($this);

    expect(assistantMessages($this))->toBe(['Avec plaisir, pour quelles dates ?']);

    // A reaction to the reply: nothing is sent, nobody is alerted.
    webhookPost($this, [inbound('wamid.R2', message: ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.OUT2', 'emoji' => '👍']])]);
    runReplies($this);

    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    expect(assistantMessages($this))->toHaveCount(1)
        ->and($conversation->mode)->toBe(ConversationMode::Assistant)
        ->and($conversation->handover_reason)->toBeNull()
        ->and(($this->in)(fn () => Message::query()->where('type', 'reaction')->pluck('body')->all()))->toBe(['👍', '👍']);
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

it('flags a complaint for staff and keeps answering; only a request for a person takes over', function (): void {
    HotelAssistantAgent::fake([
        aiReply('Je suis désolé. Un collègue s\'en occupe tout de suite.', ['intent' => 'complaint']),
        aiReply('Bien sûr, pour quelles dates ?', ['intent' => 'availability_question']),
    ]);
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['handover_messages' => ['fr' => 'La réception vous répond tout de suite.']]));

    webhookPost($this, [inbound('wamid.IN1', message: ['text' => ['body' => 'La clim ne marche pas, c’est inadmissible']])]);
    runReplies($this);

    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    expect($conversation->mode)->toBe(ConversationMode::Assistant)
        ->and($conversation->needs_attention)->toBeTrue()
        ->and($conversation->handover_reason)->toBe('complaint')
        ->and(assistantMessages($this))->toBe(['Je suis désolé. Un collègue s\'en occupe tout de suite.']);

    $reception = staffMember($this->hotel, SystemRole::Reception);
    asStaffOf($this->hotel, $reception)->get(route('hotel.inbox', ['filter' => 'attention']))->assertInertia(fn ($page) => $page->has('conversations', 1));

    // The guest goes on: the assistant still answers.
    $this->travel(1)->minutes();
    webhookPost($this, [inbound('wamid.IN2', message: ['text' => ['body' => 'Et une chambre pour demain ?']])]);
    runReplies($this);
    expect(assistantMessages($this))->toHaveCount(2);

    // Asking for a person hands over, with the hotel's message.
    $this->travel(1)->minutes();
    webhookPost($this, [inbound('wamid.IN3', message: ['text' => ['body' => 'Je veux parler à la réception']])]);
    runReplies($this);
    expect(($this->in)(fn () => $conversation->fresh()->mode))->toBe(ConversationMode::Staff)
        ->and(last(assistantMessages($this)))->toBe('La réception vous répond tout de suite.');

    asStaffOf($this->hotel, $reception)->put(route('hotel.inbox.mode', $conversation), ['mode' => 'assistant'])->assertRedirect();
    expect(($this->in)(fn () => $conversation->fresh()->mode))->toBe(ConversationMode::Assistant);
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

    HotelAssistantAgent::assertPrompted(fn ($prompt) => str_contains($prompt->agent->instructions(), 'Reception is closed now. Still take booking requests (rule 3): the rooms are held, and staff reply and confirm bookings from Friday 08:00'));
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

/**
 * The "typing…" requests sent to WhatsApp, as the guest message IDs they were for.
 *
 * @return list<string>
 */
function typingFor(): array
{
    return Http::recorded(fn ($request): bool => isset($request->data()['typing_indicator']))
        ->map(fn (array $pair): string => (string) $pair[0]->data()['message_id'])->values()->all();
}

it('shows "typing…" in the guest chat while the assistant prepares an automatic answer', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['mode' => 'automatic']));
    HotelAssistantAgent::fake([aiReply('Bonjour ! Comment puis-je vous aider ?', ['intent' => 'greeting'])]);

    webhookPost($this, [inbound('wamid.TYPE1', message: ['text' => ['body' => 'Bonjour']])]);
    runReplies($this);

    $typing = Http::recorded(fn ($request): bool => isset($request->data()['typing_indicator']))->first()[0];
    expect(typingFor())->toBe(['wamid.TYPE1'])
        ->and($typing->data())->toMatchArray(['messaging_product' => 'whatsapp', 'status' => 'read', 'typing_indicator' => ['type' => 'text']])
        ->and(assistantMessages($this))->toBe(['Bonjour ! Comment puis-je vous aider ?']);
});

it('does not show "typing…" when staff approve drafts first, or answer themselves', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['mode' => 'supervised']));
    HotelAssistantAgent::fake([aiReply('Bonjour !', ['intent' => 'greeting'])]);

    webhookPost($this, [inbound('wamid.TYPE2', message: ['text' => ['body' => 'Bonjour']])]);
    runReplies($this);

    ($this->in)(fn () => Conversation::query()->update(['mode' => ConversationMode::Staff->value]));
    webhookPost($this, [inbound('wamid.TYPE3', message: ['text' => ['body' => 'Allô ?']])]);
    runReplies($this);

    expect(typingFor())->toBe([]);
});

it('keeps "typing…" on during a long answer and never fails the reply when WhatsApp refuses it', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['mode' => 'automatic']));
    $typing = app(App\Domains\Conversations\TypingIndicator::class);
    webhookPost($this, [inbound('wamid.TYPE4', message: ['text' => ['body' => 'Bonjour']])]);
    $message = ($this->in)(fn () => Message::query()->where('wamid', 'wamid.TYPE4')->sole());

    ($this->in)(function () use ($typing, $message): void {
        $typing->start($message);
        $typing->keepAlive();               // too soon: nothing new
        $this->travel(19)->seconds();
        $typing->keepAlive();               // refreshed before WhatsApp hides it
        $typing->stop();
        $this->travel(30)->seconds();
        $typing->keepAlive();               // stopped: nothing
    });
    expect(typingFor())->toBe(['wamid.TYPE4', 'wamid.TYPE4']);

    Http::fake(['graph.facebook.com/v23.0/5550099/messages' => Http::response(['error' => ['message' => 'nope']], 400)]);
    expect(app(App\Domains\WhatsApp\Graph\GraphClient::class)->typing('5550099', 'hotel-token', 'wamid.TYPE4'))->toBeFalse();
});

it('takes over only when the guest asks for a person, not for a keyword in an ordinary sentence', function (string $text, bool $takeOver): void {
    HotelAssistantAgent::fake([aiReply('Je vérifie avec la réception, qui vous confirmera. Voulez-vous les prix ?', ['intent' => 'other'])]);

    webhookPost($this, [inbound('wamid.KW1', message: ['text' => ['body' => $text]])]);
    runReplies($this);

    expect(($this->in)(fn () => Conversation::query()->sole()->mode))->toBe($takeOver ? ConversationMode::Staff : ConversationMode::Assistant);
})->with([
    'keyword alone' => ['Réception ?', true],
    'asks to talk' => ['Je veux parler à la réception', true],
    'asks for a call' => ['Can a real person call me please', true],
    'check with reception' => ['Can you confirm from reception??', false],
    'room for one person' => ['Une chambre pour une personne le 12 décembre', false],
    'staff in a question' => ['Does the staff speak English at night? Is the pool open?', false],
    'call me' => ['Please ask a person to call me back', true],
]);

it('answers again a guest passed to staff when nobody replied for 30 minutes, and keeps it flagged', function (): void {
    HotelAssistantAgent::fake([
        aiReply('Bien sûr, pour quelles dates ?', ['intent' => 'availability_question']),
    ]);

    webhookPost($this, [inbound('wamid.R1', message: ['text' => ['body' => 'Réception']])]);
    runReplies($this);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    expect($conversation->mode)->toBe(ConversationMode::Staff);

    // Too soon: still with staff.
    $this->travel(10)->minutes();
    webhookPost($this, [inbound('wamid.R2', message: ['text' => ['body' => 'Il y a quelqu’un ?']])]);
    runReplies($this);
    expect(($this->in)(fn () => $conversation->fresh()->mode))->toBe(ConversationMode::Staff);

    $this->travel(25)->minutes();
    webhookPost($this, [inbound('wamid.R3', message: ['text' => ['body' => 'Je veux réserver une chambre pour 2']])]);
    runReplies($this);

    $fresh = ($this->in)(fn () => $conversation->fresh());
    expect($fresh->mode)->toBe(ConversationMode::Assistant)
        ->and($fresh->needs_attention)->toBeTrue()
        ->and($fresh->handover_reason)->toBe('no_staff_reply')
        ->and(last(assistantMessages($this)))->toBe('Bien sûr, pour quelles dates ?');
});

it('never takes back a conversation staff took over or answered', function (): void {
    $reception = staffMember($this->hotel, SystemRole::Reception);
    HotelAssistantAgent::fake([aiReply('Bonjour', ['intent' => 'greeting'])]);

    webhookPost($this, [inbound('wamid.S1', message: ['text' => ['body' => 'Bonjour']])]);
    runReplies($this);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    asStaffOf($this->hotel, $reception)->put(route('hotel.inbox.mode', $conversation), ['mode' => 'staff'])->assertRedirect();

    $this->travel(2)->hours();
    webhookPost($this, [inbound('wamid.S2', message: ['text' => ['body' => 'Une chambre ?']])]);
    runReplies($this);

    expect(($this->in)(fn () => $conversation->fresh()->mode))->toBe(ConversationMode::Staff)
        ->and(assistantMessages($this))->toHaveCount(1);
});
