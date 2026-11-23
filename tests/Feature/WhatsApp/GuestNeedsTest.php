<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Assistant\Knowledge\KnowledgeSection;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestCard;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Onboarding\Questionnaire;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\ToolCall;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

/*
 * 0.37.0: pets, children, accessibility and facilities in the information
 * pack, and what the guest asks for travelling with the booking request.
 */
beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]])]);
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
    webhookPost($this, [inbound('wamid.pets1', from: '2250700000009', name: 'Awa')]);
    $this->conversation = ($this->in)(fn () => Conversation::query()->with('contact.guest')->sole());
});

it('has pack sections for pets, children, accessibility and facilities, each with a hint', function (): void {
    foreach (['pets', 'children', 'accessibility', 'amenities'] as $value) {
        expect(KnowledgeSection::tryFrom($value))->not->toBeNull();
    }
    foreach (KnowledgeSection::cases() as $section) {
        expect($section->hint())->not->toBe('');
    }

    expect(Questionnaire::sections())->toMatchArray(['pets' => 'pets', 'children' => 'children', 'accessibility' => 'accessibility', 'wifi' => 'amenities'])
        ->and(array_keys(Questionnaire::questions()))->toEqualCanonicalizing(array_keys(Questionnaire::sections()));
});

it('gives the assistant the pet rules under their own heading', function (): void {
    $text = ($this->in)(function (): string {
        KnowledgeEntry::query()->create(['locale' => 'fr', 'section' => 'pets', 'content' => 'Petits chiens acceptés, 2 000 FCFA par nuit.']);
        app(KnowledgePack::class)->publish();

        return app(KnowledgePack::class)->compile('fr');
    });

    expect($text)->toContain('## '.__('Pets'))->toContain('Petits chiens acceptés');
});

it('accepts the pets section on the information pack page', function (): void {
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))
        ->get(route('hotel.assistant.knowledge'))
        ->assertInertia(fn ($page) => $page->where('sections', fn ($sections): bool => collect($sections)->contains(fn ($s): bool => $s['value'] === 'pets' && $s['hint'] !== '')));
});

it('sends what the guest asked for with the booking request, to reception and into the booking', function (): void {
    HotelAssistantAgent::fake([
        new ToolCall('c1', 'request_booking', ['guest_name' => 'Awa Koné', 'category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2, 'special_requests' => "  Travelling with a small dog (5 kg),\n late arrival around 23:00 "]),
        aiReply('Thank you Awa! Your request for 50 000 FCFA was sent to reception, who will confirm it.', ['intent' => 'booking_request', 'language' => 'en']),
    ]);

    ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Book it for Awa Koné, we come with our dog')], $this->conversation->id));

    $request = ($this->in)(fn () => BookingRequest::query()->sole());
    expect($request->guest_notes)->toBe('Travelling with a small dog (5 kg), late arrival around 23:00')
        ->and(($this->in)(fn () => implode("\n", BookingRequestCard::lines($request, 'en'))))->toContain('Guest asks: Travelling with a small dog');

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false])->assertSessionHasNoErrors();

    expect(($this->in)(fn () => Booking::query()->sole()->notes))->toContain('Travelling with a small dog (5 kg)');
});

it('keeps a request without wishes as before', function (): void {
    $request = ($this->in)(fn () => app(BookingRequestService::class)->submit(
        $this->conversation,
        new App\Domains\Rates\Quote\StayRequest($this->exe->id, Carbon\CarbonImmutable::parse('2026-11-12'), Carbon\CarbonImmutable::parse('2026-11-14'), adults: 2),
        'Awa Koné',
        null,
        '   ',
    ));

    expect($request->guest_notes)->toBeNull()
        ->and(BookingRequestService::cleanNotes(str_repeat('a', 600)))->toHaveLength(500);
});

it('never lets the assistant say a request was sent when none was', function (): void {
    HotelAssistantAgent::fake([
        aiReply('Your booking request for 2 rooms has been sent to reception under the name Srini.', ['intent' => 'booking_request', 'language' => 'en']),
        new ToolCall('c1', 'request_booking', ['guest_name' => 'Srini Rao', 'category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2]),
        aiReply('Your booking request has been sent to reception, who will confirm it.', ['intent' => 'booking_request', 'language' => 'en']),
    ]);

    $result = ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Yes, book it, pay on arrival')], $this->conversation->id));

    expect($result->attempts)->toBe(2)
        ->and(collect($result->guardrails)->firstWhere('check', 'request_really_sent')['passed'])->toBeTrue()
        ->and(($this->in)(fn () => BookingRequest::query()->count()))->toBe(1);
});

it('lets the assistant mention a request already waiting for reception', function (): void {
    ($this->in)(fn () => app(BookingRequestService::class)->submit(
        $this->conversation,
        new App\Domains\Rates\Quote\StayRequest($this->exe->id, Carbon\CarbonImmutable::parse('2026-11-12'), Carbon\CarbonImmutable::parse('2026-11-14'), adults: 2),
        'Awa Koné',
    ));
    HotelAssistantAgent::fake([aiReply('Votre demande a bien été transmise à la réception, qui vous répondra rapidement.', ['intent' => 'other'])]);

    $result = ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Alors, c’est bon ?')], $this->conversation->id));

    expect($result->attempts)->toBe(1)->and($result->handover)->toBeFalse();
});
