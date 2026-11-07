<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Events\EventStatus;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\EventQuote;
use App\Domains\Events\Models\RoomBlock;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\StarterTemplates;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Responses\Data\ToolCall;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../WhatsApp/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    $this->travelTo('2026-11-13 10:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->s = eventSetup($this->hotel);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));
    $this->sent = [];
    Http::fake(['graph.facebook.com/v23.0/5550001/messages' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.E'.count($this->sent)]]]);
    }]);
    webhookPost($this, [inbound('wamid.G1', from: '2250700000031', name: 'Awa')]);
    $this->conversation = ($this->in)(fn () => Conversation::query()->sole());
    $this->ask = fn (string $text) => ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest($text)], $this->conversation->id));
    $this->coordinator = staffMember($this->hotel, SystemRole::EventsCoordinator);
});

function enquiryCall(string $name = 'Awa Koné'): ToolCall
{
    return new ToolCall('e1', 'event_enquiry', ['type' => 'seminar', 'date' => '2026-12-12', 'people' => 40, 'time' => 'Journée', 'needs' => 'Déjeuner, vidéoprojecteur', 'contact_name' => $name, 'company' => 'Ivoire Tech']);
}

it('creates an event enquiry from WhatsApp and alerts the events team', function (): void {
    ($this->in)(fn () => StaffAlertSettings::query()->create(['user_id' => $this->coordinator->id, 'alert_phone' => '+2250700000060', 'alerts' => ['event_enquiry']]));
    ($this->in)(function (): void {
        $starter = StarterTemplates::all()['staff_alert'];
        MessageTemplate::query()->create(['starter_key' => 'staff_alert', 'name' => 'staff_alert', 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]);
    });
    HotelAssistantAgent::fake([enquiryCall(), aiReply('Merci Awa, notre équipe événements prépare votre devis et vous contacte.', ['intent' => 'event_enquiry'])]);

    $result = ($this->ask)('Séminaire de 40 personnes le 12 décembre, journée complète avec déjeuner');

    $event = ($this->in)(fn () => Event::query()->sole());
    expect($result->handover)->toBeFalse()
        ->and($event->source)->toBe('whatsapp')
        ->and($event->conversation_id)->toBe($this->conversation->id)
        ->and($event->phone)->toBe('+2250700000031')
        ->and($event->event_block_id)->toBe($this->s['day']->id)
        ->and($event->company)->toBe('Ivoire Tech')
        ->and(collect($this->sent)->pluck('to')->all())->toContain('2250700000060');
});

it('asks for the name first, and creates nothing from the practice screen', function (): void {
    HotelAssistantAgent::fake([enquiryCall(''), aiReply('À quel nom puis-je enregistrer la demande ?', ['intent' => 'event_enquiry'])]);
    expect(($this->ask)('Séminaire le 12')->tools[0]['result'])->toContain('contact name');

    HotelAssistantAgent::fake([enquiryCall(), aiReply('Noté.', ['intent' => 'event_enquiry'])]);
    $practice = ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Séminaire')]));
    expect($practice->tools[0]['result'])->toContain('"practice":true')
        ->and(($this->in)(fn () => Event::query()->count()))->toBe(0);
});

it('never lets the assistant give an event price', function (): void {
    HotelAssistantAgent::fake([
        aiReply('La salle coûte 150 000 FCFA la journée.', ['intent' => 'event_enquiry']),
        aiReply('La salle coûte 150 000 FCFA la journée.', ['intent' => 'event_enquiry']),
    ]);

    expect(($this->ask)('Combien coûte la salle ?')->handoverReason)->toBe('guardrail');
});

it('tells the assistant about event spaces without prices', function (): void {
    $pack = ($this->in)(fn () => app(KnowledgePack::class)->compile('fr'));

    expect($pack)->toContain('Salle A: up to')->toContain('60')->toContain('Event spaces (events team quotes them; no prices to guests');
});

it('runs an event from a WhatsApp enquiry to confirmation with attendee rooms', function (): void {
    // 1. WhatsApp enquiry.
    HotelAssistantAgent::fake([enquiryCall(), aiReply('Notre équipe vous envoie un devis.', ['intent' => 'event_enquiry'])]);
    ($this->ask)('Séminaire de 40 personnes le 12 décembre');
    $event = ($this->in)(fn () => Event::query()->sole());
    asStaffOf($this->hotel, $this->coordinator)->put(route('hotel.events.update', $event), [
        'client_name' => 'Awa Koné', 'company' => 'Ivoire Tech', 'type' => 'seminar', 'date' => '2026-12-12',
        'event_block_id' => $this->s['day']->id, 'event_space_id' => $this->s['a']->id, 'layout' => 'theatre', 'people' => 40,
    ])->assertSessionHasNoErrors();

    // 2. Quote, option, link sent on WhatsApp.
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.quotes.store', $event), ['lines' => [['kind' => 'free', 'description' => 'Forfait séminaire', 'unit_price' => 20000, 'quantity' => 40]]])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.option', $event))->assertSessionHasNoErrors();
    $quote = ($this->in)(fn () => EventQuote::query()->sole());
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.event-quotes.send', $quote))->assertSessionHasNoErrors();
    $messages = ($this->in)(fn () => Message::query()->where('source', MessageSource::System->value)->orderBy('id')->pluck('body')->all());
    expect($messages[0])->toContain('/q/');
    $path = '/q/'.explode('/q/', $messages[0])[1];

    // 3. Online acceptance, thank-you on WhatsApp.
    $this->flushSession();
    $this->post($path, ['name' => 'Awa Koné', 'title' => 'Directrice', 'terms' => '1'])->assertRedirect();
    expect(($this->in)(fn () => Message::query()->where('source', MessageSource::System->value)->count()))->toBe(2);

    // 4. Deposit (30 %), confirmation, event sheet.
    $reception = staffMember($this->hotel, SystemRole::Reception);
    asStaffOf($this->hotel, $reception)->post(route('hotel.events.payments.store', $event), ['amount' => 240000, 'method' => 'orange_money', 'reference' => 'OM-1'])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.confirm', $event))->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.sheet.save', $event), ['timings' => [['time' => '08:30', 'what' => 'Accueil']], 'kitchen' => '40 déjeuners'])->assertSessionHasNoErrors();

    // 5. Ten attendee rooms; six booked, four go back on sale at the cut-off.
    $category = $this->exe;
    $total = ($this->in)(fn () => App\Domains\Rooms\Models\Room::query()->where('room_category_id', $category->id)->count());
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.room-blocks.store', $event), [
        'room_category_id' => $category->id, 'check_in' => '2026-12-11', 'check_out' => '2026-12-13', 'rooms' => min(10, $total), 'rate' => 18000, 'cutoff_date' => '2026-11-21',
    ])->assertSessionHasNoErrors();
    $block = ($this->in)(fn () => RoomBlock::query()->sole());
    $booked = min(6, $block->rooms - 1);
    foreach (range(1, $booked) as $i) {
        asStaffOf($this->hotel, $reception)->post(route('hotel.room-blocks.book', $block), ['first_name' => "Invité {$i}", 'adults' => 1])->assertSessionHasNoErrors();
    }
    $this->travelTo('2026-11-22 08:00:00');
    $this->artisan('events:release-room-blocks');

    expect(($this->in)(fn () => $event->fresh()->status))->toBe(EventStatus::Confirmed)
        ->and(($this->in)(fn () => $block->fresh()->booked_rooms))->toBe($booked)
        ->and(($this->in)(fn () => $block->fresh()->released_at))->not->toBeNull()
        ->and(($this->in)(fn () => app(App\Domains\Folio\Folio::class)->balance($event->fresh())))->toBe(($this->in)(fn () => $quote->fresh()->total_minor) - 240000);
});
