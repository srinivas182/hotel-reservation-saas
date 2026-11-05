<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\StarterTemplates;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Responses\Data\ToolCall;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Availability/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));
    $this->sent = [];
    Http::fake(['graph.facebook.com/v23.0/5550001/messages' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.OUT'.count($this->sent)]]]);
    }]);
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
});

function guestConversation(object $test): Conversation
{
    // The assistant is off here, so the reply job does nothing.
    webhookPost($test, [inbound('wamid.G1', name: 'Awa')]);

    return ($test->in)(fn () => Conversation::query()->with('contact.guest')->sole());
}

function pendingRequest(object $test, Conversation $conversation): BookingRequest
{
    return ($test->in)(fn () => app(BookingRequestService::class)->submit(
        $conversation,
        new StayRequest($test->exe->id, CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-14'), adults: 2),
        'Awa Koné',
    ));
}

function systemMessages(object $test): array
{
    return ($test->in)(fn () => Message::query()->where('source', MessageSource::System->value)->orderBy('id')->get()->all());
}

function approvedStarterTemplate(object $test, string $name, string $language = 'fr'): void
{
    $starter = StarterTemplates::all()[$name];
    ($test->in)(fn () => MessageTemplate::query()->create([
        'starter_key' => $name, 'name' => $name, 'language' => $language, 'category' => 'UTILITY',
        'body' => $starter['bodies'][$language], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved, 'meta_template_id' => 'tpl-'.$name,
    ]));
}

it('tells the guest their booking is confirmed, in their language, within 24 hours', function (): void {
    $conversation = guestConversation($this);
    $request = pendingRequest($this, $conversation);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false]);

    $booking = ($this->in)(fn () => Booking::query()->sole());
    $message = systemMessages($this)[0];

    expect($message->type)->toBe('text')
        ->and($message->body)->toContain('Bonjour Awa')
        ->and($message->body)->toContain($booking->reference)
        ->and($message->body)->toContain('12 novembre 2026')
        ->and($message->body)->toContain('50')
        ->and(($this->in)(fn () => $request->fresh()->guest_notified))->toBe('text');
});

it('uses the approved template after 24 hours', function (): void {
    $conversation = guestConversation($this);
    $request = pendingRequest($this, $conversation);
    approvedStarterTemplate($this, 'booking_confirmed');
    $this->travel(25)->hours();
    ($this->in)(fn () => $request->forceFill(['expires_at' => now()->addHour()])->save());

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false])->assertSessionHasNoErrors();

    $sent = end($this->sent);
    expect($sent['type'])->toBe('template')
        ->and($sent['template']['name'])->toBe('booking_confirmed')
        ->and($sent['template']['components'][0]['parameters'])->toHaveCount(7)
        ->and($sent['template']['components'][0]['parameters'][6]['text'])->toBe('First Class Lodge');
});

it('asks staff to contact the guest when no template is approved', function (): void {
    $conversation = guestConversation($this);
    $request = pendingRequest($this, $conversation);
    $this->travel(25)->hours();
    ($this->in)(fn () => $request->forceFill(['expires_at' => now()->addHour()])->save());

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.decline', $request), ['reason' => 'full']);

    $conversation = ($this->in)(fn () => $conversation->fresh());
    expect($conversation->needs_attention)->toBeTrue()
        ->and($conversation->handover_reason)->toBe('guest_not_told:declined')
        ->and(systemMessages($this))->toBe([]);
});

it('asks for a deposit and keeps the booking tentative until reception confirms', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update([
        'deposit_required' => true, 'deposit_percent' => 30, 'deposit_hours' => 24,
        'deposit_instructions' => ['fr' => 'Orange Money 07 00 00 00 00 au nom de First Class Lodge'],
    ]));
    $conversation = guestConversation($this);
    $request = pendingRequest($this, $conversation);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false]);

    $booking = ($this->in)(fn () => Booking::query()->sole());
    expect($booking->status)->toBe(BookingStatus::Tentative)
        ->and($booking->tentative_until?->toDateTimeString())->toBe('2026-11-02 10:00:00')
        ->and($booking->payment_note)->toContain('30');

    $message = systemMessages($this)[0]->body;
    expect($message)->toContain('acompte')
        ->and($message)->toContain('15')
        ->and($message)->toContain('Orange Money 07 00 00 00 00');

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.confirm', $booking))->assertRedirect();
    expect(($this->in)(fn () => $booking->fresh()->status))->toBe(BookingStatus::Confirmed);
});

it('tells the guest when a request is declined or not answered in time, with the hotel\'s own wording', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['decision_messages' => ['fr' => ['expired' => 'Bonjour {name}, la réception vous rappelle pour le {check_in}.']]]));
    $conversation = guestConversation($this);
    pendingRequest($this, $conversation);
    $this->travel(121)->minutes();

    $this->artisan('booking-requests:expire');

    expect(systemMessages($this)[0]->body)->toBe('Bonjour Awa, la réception vous rappelle pour le 12 novembre 2026.');
});

it('requires payment instructions when a deposit is required', function (): void {
    $manager = staffMember($this->hotel, SystemRole::Manager);

    asStaffOf($this->hotel, $manager)->put(route('hotel.assistant.update'), [
        'mode' => 'off', 'tone' => 'formal', 'languages' => ['fr'], 'daily_budget' => 5, 'kill_switch' => false,
        'request_hold_minutes' => 120, 'deposit_required' => true, 'deposit_percent' => 30, 'deposit_hours' => 24, 'deposit_instructions' => ['fr' => ''],
    ])->assertSessionHasErrors('deposit_instructions');
});

it('has starter templates Meta will accept', function (): void {
    foreach (StarterTemplates::all() as $key => $starter) {
        foreach ($starter['bodies'] as $language => $body) {
            $template = new MessageTemplate(['body' => $body, 'examples' => $starter['examples']]);

            expect($template->placeholders())->toBe(count($starter['examples']), "{$key} {$language}")
                ->and($body)->not->toMatch('/^\{\{\d+\}\}/')
                ->and($body)->not->toMatch('/\{\{\d+\}\}$/')
                ->and($body)->not->toMatch('/\{\{\d+\}\}\s*\{\{\d+\}\}/');
        }
    }
});

it('runs the whole flow: guest, assistant, request, reception, booking, confirmation', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['enabled' => true]));
    HotelAssistantAgent::fake([
        new ToolCall('c1', 'get_quote', ['category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2]),
        aiReply('Oui, la chambre Exécutive est disponible du 12 au 14 novembre : 50 000 FCFA. Souhaitez-vous réserver ? Merci d’indiquer votre nom complet.'),
        new ToolCall('c2', 'request_booking', ['guest_name' => 'Awa Koné', 'category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2]),
        aiReply('Merci Awa ! Votre demande est transmise à la réception, qui vous confirmera très vite.', ['intent' => 'booking_request']),
    ]);

    webhookPost($this, [inbound('wamid.E1', message: ['text' => ['body' => 'Bonjour, une chambre exécutive du 12 au 14 novembre pour 2 personnes ?']], name: 'Awa')]);
    $this->travel(1)->minute();
    webhookPost($this, [inbound('wamid.E2', message: ['text' => ['body' => 'Oui je réserve. Awa Koné']], name: 'Awa')]);

    $request = ($this->in)(fn () => BookingRequest::query()->sole());
    expect(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-12'))->toBe(['2026-11-12' => 2]);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false])->assertSessionHasNoErrors();

    $booking = ($this->in)(fn () => Booking::query()->sole());
    $messages = ($this->in)(fn () => Message::query()->where('direction', 'out')->orderBy('id')->pluck('source')->map->value->all());

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($messages)->toBe(['assistant', 'assistant', 'system'])
        ->and(systemMessages($this)[0]->body)->toContain($booking->reference)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-13'))->toBe(['2026-11-12' => 2, '2026-11-13' => 2]);
});
