<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\Conversations\ConversationMode;
use App\Domains\Conversations\ConversationNeedsStaff;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\MessageStatus;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\ToolCall;

require_once __DIR__.'/../WhatsApp/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class]);
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $this->hotel] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(function (): void {
        WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']);
        HotelAssistantSettings::current()->update(['mode' => AssistantMode::Supervised, 'privacy_url' => 'https://lodge.example/privacy']);
    });
    $this->sent = 0;
    Http::fake(['graph.facebook.com/v23.0/5550001/messages' => function () {
        $this->sent++;

        return Http::response(['messages' => [['id' => 'wamid.O'.$this->sent]]]);
    }]);
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
});

function runQueuedReplies(object $test): void
{
    $jobs = Queue::pushed(ReplyToGuest::class)->all();
    Queue::fake([ReplyToGuest::class]);

    foreach ($jobs as $job) {
        ($test->in)(fn () => app()->call([$job, 'handle']));
    }
}

function guestSays(object $test, string $wamid, string $text): void
{
    webhookPost($test, [inbound($wamid, message: ['text' => ['body' => $text]], name: 'Awa')]);
    runQueuedReplies($test);
}

it('keeps the assistant\'s reply as a draft and sends nothing', function (): void {
    HotelAssistantAgent::fake([aiReply('Bonjour ! Pour quelles dates ?', ['intent' => 'greeting'])]);

    guestSays($this, 'wamid.I1', 'Bonjour');

    $draft = ($this->in)(fn () => Message::query()->where('direction', 'out')->sole());
    expect($draft->status)->toBe(MessageStatus::Draft)
        ->and($draft->body)->toStartWith('Bonjour ! Pour quelles dates ?')
        ->and($this->sent)->toBe(0)
        ->and(($this->in)(fn () => Conversation::query()->sole()->waiting_since))->not->toBeNull();
});

it('sends a draft unchanged or edited, or discards it, and records the outcome', function (): void {
    HotelAssistantAgent::fake([
        aiReply('Réponse un.', ['intent' => 'greeting']),
        aiReply('Réponse deux.', ['intent' => 'greeting']),
        aiReply('Réponse trois.', ['intent' => 'greeting']),
    ]);

    foreach (['I1', 'I2', 'I3'] as $i => $id) {
        $this->travel(1)->minute();
        guestSays($this, "wamid.{$id}", "Question {$i}");
    }
    $drafts = ($this->in)(fn () => Message::query()->where('status', 'draft')->orderBy('sent_at')->get());

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.inbox.drafts.send', $drafts[0]))->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.inbox.drafts.send', $drafts[1]), ['body' => 'Réponse deux, corrigée.'])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.inbox.drafts.discard', $drafts[2]))->assertRedirect();

    $outcomes = ($this->in)(fn () => Message::query()->whereIn('id', $drafts->pluck('id'))->orderBy('sent_at')->get());
    expect($outcomes->pluck('draft_outcome')->all())->toBe(['sent_unchanged', 'edited', 'discarded'])
        ->and($outcomes[1]->body)->toBe('Réponse deux, corrigée.')
        ->and($outcomes[0]->status)->toBe(MessageStatus::Accepted)
        ->and($this->sent)->toBe(2)
        ->and(($this->in)(fn () => Conversation::query()->sole()->waiting_since))->toBeNull();

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Owner))->get(route('hotel.go-live'))
        ->assertInertia(fn ($page) => $page->where('drafts.handled', 3)->where('drafts.unchanged_rate', 33));

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))->post(route('hotel.inbox.drafts.send', $drafts[2]))->assertForbidden();
});

it('still creates booking requests in supervised mode', function (): void {
    HotelAssistantAgent::fake([
        new ToolCall('c1', 'request_booking', ['guest_name' => 'Awa Koné', 'category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2]),
        aiReply('Votre demande est transmise à la réception.', ['intent' => 'booking_request']),
    ]);

    guestSays($this, 'wamid.I1', 'Je réserve, Awa Koné');

    expect(($this->in)(fn () => BookingRequest::query()->count()))->toBe(1)->and($this->sent)->toBe(0);
});

it('alerts staff about a draft nobody approved, without messaging the guest', function (): void {
    Event::fake([ConversationNeedsStaff::class]);
    HotelAssistantAgent::fake([aiReply('Bonjour !', ['intent' => 'greeting'])]);
    guestSays($this, 'wamid.I1', 'Bonjour');

    $this->travel(16)->minutes();
    $this->artisan('conversations:check-waiting');

    Event::assertDispatched(ConversationNeedsStaff::class, fn ($e) => $e->reason === 'guest_waiting');
    expect($this->sent)->toBe(0);
});

it('passes the conversation to staff without messaging the guest in supervised mode', function (): void {
    HotelAssistantAgent::fake([aiReply('Je transmets.', ['intent' => 'complaint'])]);

    guestSays($this, 'wamid.I1', 'C’est inadmissible');

    expect(($this->in)(fn () => Conversation::query()->sole()->mode))->toBe(ConversationMode::Staff)
        ->and($this->sent)->toBe(0);
});

it('tells the guest once that this is a virtual assistant, with the privacy link', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['mode' => AssistantMode::Automatic]));
    HotelAssistantAgent::fake([aiReply('Bonjour !', ['intent' => 'greeting']), aiReply('Pour quelles dates ?', ['intent' => 'availability_question'])]);

    guestSays($this, 'wamid.I1', 'Bonjour');
    $this->travel(1)->minute();
    guestSays($this, 'wamid.I2', 'Une chambre ?');

    $replies = ($this->in)(fn () => Message::query()->where('direction', 'out')->orderBy('sent_at')->pluck('body')->all());
    expect($replies[0])->toContain('First Class Lodge')
        ->and($replies[0])->toContain('« réception »')
        ->and($replies[0])->toContain('https://lodge.example/privacy')
        ->and($replies[1])->toBe('Pour quelles dates ?');
});

it('always passes a guest who asks for a person to staff', function (string $text): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['mode' => AssistantMode::Automatic]));
    HotelAssistantAgent::fake();

    guestSays($this, 'wamid.I1', $text);

    expect(($this->in)(fn () => Conversation::query()->sole()->handover_reason))->toBe('guest_asked_for_person');
    HotelAssistantAgent::assertNeverPrompted();
})->with(['Réception svp', 'je veux parler à une PERSONNE', 'Human please', 'RECEPTION']);
