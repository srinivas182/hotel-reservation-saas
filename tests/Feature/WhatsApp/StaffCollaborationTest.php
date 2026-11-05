<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\AiRun;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Conversations\ConversationMode;
use App\Domains\Conversations\ConversationNeedsStaff;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\ConversationNote;
use App\Domains\Conversations\Models\Message;
use App\Domains\Conversations\Translation\TranslationAgent;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Queue::fake([ReplyToGuest::class]);
    $this->travelTo('2026-11-12 10:00:00');
    ['hotel' => $this->hotel] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));
    Http::fake(['graph.facebook.com/v23.0/5550001/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]])]);
    $this->amara = staffMember($this->hotel, SystemRole::Reception);
    $this->kofi = staffMember($this->hotel, SystemRole::Reception);
    webhookPost($this, [inbound('wamid.IN1', message: ['text' => ['body' => 'Hello, is breakfast included?']], name: 'John Smith')]);
    $this->conversation = ($this->in)(fn () => Conversation::query()->sole());
});

it('assigns the conversation to whoever takes over, and can reassign or unassign it', function (): void {
    asStaffOf($this->hotel, $this->amara)->put(route('hotel.inbox.mode', $this->conversation), ['mode' => 'staff']);
    expect(($this->in)(fn () => $this->conversation->fresh()->assigned_to))->toBe($this->amara->id);

    asStaffOf($this->hotel, $this->amara)->get(route('hotel.inbox', ['filter' => 'mine']))->assertInertia(fn ($page) => $page->has('conversations', 1));
    asStaffOf($this->hotel, $this->kofi)->get(route('hotel.inbox', ['filter' => 'mine']))->assertInertia(fn ($page) => $page->has('conversations', 0));

    asStaffOf($this->hotel, $this->amara)->put(route('hotel.inbox.assign', $this->conversation), ['user_id' => $this->kofi->id])->assertRedirect();
    expect(($this->in)(fn () => $this->conversation->fresh()->assigned_to))->toBe($this->kofi->id);

    $readOnly = staffMember($this->hotel, SystemRole::ReadOnly);
    asStaffOf($this->hotel, $this->amara)->put(route('hotel.inbox.assign', $this->conversation), ['user_id' => $readOnly->id])->assertSessionHasErrors('user_id');

    asStaffOf($this->hotel, $this->kofi)->put(route('hotel.inbox.mode', $this->conversation), ['mode' => 'assistant']);
    expect(($this->in)(fn () => $this->conversation->fresh()->assigned_to))->toBeNull()
        ->and(AuditLog::query()->where('event', 'conversations.assigned')->count())->toBe(1);
});

it('keeps internal notes encrypted, shows them to staff and never sends them', function (): void {
    asStaffOf($this->hotel, $this->amara)->post(route('hotel.inbox.notes', $this->conversation), ['body' => 'Already called him about the airport pickup'])->assertRedirect();

    expect(DB::table('conversation_notes')->value('body'))->not->toContain('airport')
        ->and(($this->in)(fn () => ConversationNote::query()->sole()->body))->toBe('Already called him about the airport pickup')
        ->and(($this->in)(fn () => Message::query()->where('direction', 'out')->count()))->toBe(0);
    Http::assertNothingSent();

    asStaffOf($this->hotel, $this->kofi)->get(route('hotel.inbox.show', $this->conversation))
        ->assertInertia(fn ($page) => $page->where('conversation.notes.0.body', 'Already called him about the airport pickup')->where('conversation.notes.0.by', $this->amara->name));

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))->post(route('hotel.inbox.notes', $this->conversation), ['body' => 'x'])->assertForbidden();
});

it('sends one holding message to a guest left waiting, and alerts staff again', function (): void {
    Event::fake([ConversationNeedsStaff::class]);
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['silence_minutes' => 15, 'handover_messages' => ['en' => 'Our team will reply shortly.']]));
    ($this->in)(fn () => $this->conversation->contact->guest->update(['preferred_locale' => 'en']));
    asStaffOf($this->hotel, $this->amara)->put(route('hotel.inbox.mode', $this->conversation), ['mode' => 'staff']);

    webhookPost($this, [inbound('wamid.IN2', message: ['text' => ['body' => 'Hello? Anyone?']])]);
    $this->travel(10)->minutes();
    $this->artisan('conversations:check-waiting');
    expect(($this->in)(fn () => Message::query()->where('source', MessageSource::System->value)->count()))->toBe(0);

    $this->travel(6)->minutes();
    $this->artisan('conversations:check-waiting');
    $this->artisan('conversations:check-waiting');

    $holding = ($this->in)(fn () => Message::query()->where('source', MessageSource::System->value)->get());
    expect($holding)->toHaveCount(1)
        ->and($holding->first()->body)->toBe('Our team will reply shortly.')
        ->and(($this->in)(fn () => $this->conversation->fresh()->needs_attention))->toBeTrue();
    Event::assertDispatched(ConversationNeedsStaff::class, fn ($e) => $e->reason === 'guest_waiting');

    // A staff reply resets the wait.
    asStaffOf($this->hotel, $this->amara)->post(route('hotel.inbox.reply', $this->conversation), ['body' => 'Yes, breakfast is included.']);
    expect(($this->in)(fn () => $this->conversation->fresh()->waiting_since))->toBeNull();
});

it('adds the opening time to the holding message when reception is closed', function (): void {
    ($this->in)(fn () => HotelSettings::current()->update(['staffed_hours' => ['fri' => [['from' => '08:00', 'to' => '20:00']]]]));
    ($this->in)(fn () => $this->conversation->update(['mode' => ConversationMode::Staff]));
    webhookPost($this, [inbound('wamid.IN2')]);
    $this->travel(20)->minutes();

    $this->artisan('conversations:check-waiting');

    expect(($this->in)(fn () => Message::query()->where('source', MessageSource::System->value)->sole()->body))->toContain('Reception opens at Friday 08:00.'); // The guest wrote in English.
});

it('translates a draft reply into the guest\'s language for staff to check', function (): void {
    TranslationAgent::fake(['Yes, breakfast is included in your rate.']);
    ($this->in)(fn () => $this->conversation->contact->guest->update(['preferred_locale' => 'en']));

    asStaffOf($this->hotel, $this->amara)
        ->post(route('hotel.inbox.translate', $this->conversation), ['text' => 'Oui, le petit-déjeuner est inclus dans votre tarif.', 'language' => 'en'])
        ->assertSessionHas('inertia.flash_data', fn (array $flash): bool => $flash['translation'] === 'Yes, breakfast is included in your rate.');

    // Nothing is sent until staff send it themselves.
    Http::assertNothingSent();
    expect(($this->in)(fn () => AiRun::query()->where('task', 'translation')->count()))->toBe(1);
});

it('shows a guest message in the staff member\'s language and keeps the translation', function (): void {
    TranslationAgent::fake(['Bonjour, le petit-déjeuner est-il inclus ?']);
    $this->amara->update(['preferred_locale' => 'fr']);
    $message = ($this->in)(fn () => Message::query()->sole());

    asStaffOf($this->hotel, $this->amara)->post(route('hotel.inbox.messages.translate', $message))->assertRedirect();

    $message = ($this->in)(fn () => $message->fresh());
    expect($message->translation)->toBe('Bonjour, le petit-déjeuner est-il inclus ?')
        ->and($message->translation_language)->toBe('fr')
        ->and(DB::table('messages')->where('id', $message->id)->value('translation'))->not->toContain('petit');
});

it('explains when translation is not available', function (): void {
    TranslationAgent::fake();
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['kill_switch' => true]));

    asStaffOf($this->hotel, $this->amara)
        ->post(route('hotel.inbox.translate', $this->conversation), ['text' => 'Bonjour', 'language' => 'en'])
        ->assertSessionHasErrors('body');

    TranslationAgent::assertNeverPrompted();
});
