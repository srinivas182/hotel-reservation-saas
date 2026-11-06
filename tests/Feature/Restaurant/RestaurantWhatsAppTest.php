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
use App\Domains\Restaurant\Menu\MenuItem;
use App\Domains\Restaurant\Menu\MenuSection;
use App\Domains\Restaurant\Models\TableBooking;
use App\Domains\Restaurant\TableBookingStatus;
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
    $this->travelTo('2026-11-13 15:00:00'); // Friday
    ['hotel' => $this->hotel] = assistantHotel();
    $this->outlet = restaurant($this->hotel);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(function (): void {
        WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']);
        $section = MenuSection::query()->create(['outlet_id' => $this->outlet->id, 'name' => ['fr' => 'Plats']]);
        MenuItem::query()->create(['menu_section_id' => $section->id, 'name' => ['fr' => 'Poulet braisé'], 'price_minor' => 5500, 'allergens' => ['peanuts'], 'active' => true]);
    });
    $this->sent = [];
    Http::fake(['graph.facebook.com/v23.0/5550001/messages' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.R'.count($this->sent)]]]);
    }]);
    webhookPost($this, [inbound('wamid.G1', name: 'Awa')]);
    $this->conversation = ($this->in)(fn () => Conversation::query()->sole());
    $this->ask = fn (string $text) => ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest($text)], $this->conversation->id));
});

function bookTableCall(int $people, string $time = '20:00', ?string $notes = null): ToolCall
{
    return new ToolCall('t1', 'book_table', array_filter(['outlet' => 'restaurant', 'date' => '2026-11-13', 'time' => $time, 'people' => $people, 'guest_name' => 'Awa Koné', 'notes' => $notes]));
}

function systemTexts(object $test): array
{
    return ($test->in)(fn () => Message::query()->where('source', MessageSource::System->value)->orderBy('id')->pluck('body')->all());
}

it('books a small table and lets the system send the confirmation', function (): void {
    HotelAssistantAgent::fake([
        new ToolCall('a1', 'restaurant_availability', ['outlet' => 'restaurant', 'date' => '2026-11-13', 'people' => 4]),
        bookTableCall(4),
        aiReply('Merci Awa, je vous envoie la confirmation tout de suite.', ['intent' => 'table_booking']),
    ]);

    $result = ($this->ask)('Une table pour 4 ce soir à 20 h ?');

    $booking = ($this->in)(fn () => TableBooking::query()->sole());
    expect($result->handover)->toBeFalse()
        ->and($result->tools[0]['result'])->toContain('20:00 (Dîner)')
        ->and($booking->status)->toBe(TableBookingStatus::Confirmed)
        ->and($booking->covers)->toBe(4)
        ->and(systemTexts($this)[0])->toContain($booking->reference)
        ->and(systemTexts($this)[0])->toContain('20:00')
        ->and(systemTexts($this)[0])->toContain('pour 4');
});

it('sends large parties and occasions to the restaurant team, with seats held', function (int $people, ?string $notes): void {
    $chef = staffMember($this->hotel, SystemRole::RestaurantBar);
    ($this->in)(fn () => StaffAlertSettings::query()->create(['user_id' => $chef->id, 'alert_phone' => '+2250700000050', 'alerts' => ['table_request']]));
    ($this->in)(function (): void {
        $starter = StarterTemplates::all()['staff_alert'];
        MessageTemplate::query()->create(['starter_key' => 'staff_alert', 'name' => 'staff_alert', 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]);
    });
    HotelAssistantAgent::fake([bookTableCall($people, '20:00', $notes), aiReply('Votre demande est transmise à l’équipe du restaurant.', ['intent' => 'table_booking'])]);

    ($this->ask)('Une table ce soir');

    $booking = ($this->in)(fn () => TableBooking::query()->sole());
    expect($booking->status)->toBe(TableBookingStatus::Requested)
        ->and($booking->expires_at?->toDateTimeString())->toBe('2026-11-13 17:00:00')
        ->and(collect($this->sent)->pluck('to')->all())->toContain('2250700000050')
        ->and(systemTexts($this))->toBe([]);
})->with([
    'party of 12' => [12, null],
    'birthday' => [4, 'Anniversaire de ma femme'],
]);

it('tells the guest when staff confirm or decline a request', function (string $status, string $expected): void {
    $chef = staffMember($this->hotel, SystemRole::RestaurantBar);
    HotelAssistantAgent::fake([bookTableCall(12), aiReply('Demande transmise.', ['intent' => 'table_booking'])]);
    ($this->ask)('Table pour 12');
    $booking = ($this->in)(fn () => TableBooking::query()->sole());

    asStaffOf($this->hotel, $chef)->post(route('hotel.restaurant.bookings.status', $booking), ['status' => $status])->assertSessionHasNoErrors();

    expect(systemTexts($this)[0])->toContain($expected);
})->with([
    'confirmed' => ['confirmed', 'est confirmée'],
    'declined' => ['declined', 'nous sommes désolés'],
]);

it('uses the template when the guest wrote more than 24 hours ago', function (): void {
    ($this->in)(function (): void {
        $starter = StarterTemplates::all()['table_booking_confirmed'];
        MessageTemplate::query()->create(['starter_key' => 'table_booking_confirmed', 'name' => 'table_booking_confirmed', 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]);
    });
    $booking = bookTable($this->hotel, $this->outlet, '2026-11-15', '20:00', 12, status: TableBookingStatus::Requested);
    ($this->in)(fn () => $booking->update(['conversation_id' => $this->conversation->id, 'expires_at' => now()->addDays(2)]));
    $this->travel(25)->hours();

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))->post(route('hotel.restaurant.bookings.status', $booking), ['status' => 'confirmed']);

    $sent = end($this->sent);
    expect($sent['template']['name'])->toBe('table_booking_confirmed')
        ->and($sent['template']['components'][0]['parameters'])->toHaveCount(7);
});

it('expires requests nobody answered and tells the guest', function (): void {
    HotelAssistantAgent::fake([bookTableCall(12), aiReply('Demande transmise.', ['intent' => 'table_booking'])]);
    ($this->ask)('Table pour 12');

    $this->travelTo('2026-11-13 17:05:00');
    $this->artisan('table-requests:expire');

    expect(($this->in)(fn () => TableBooking::query()->sole()->status))->toBe(TableBookingStatus::Expired)
        ->and(systemTexts($this)[0])->toContain('Notre équipe vous contactera')
        ->and(($this->in)(fn () => $this->conversation->fresh()->needs_attention))->toBeTrue();
});

it('lets the assistant quote menu prices but never invent them or confirm a table', function (): void {
    HotelAssistantAgent::fake([
        new ToolCall('m1', 'get_menu', ['outlet' => 'restaurant']),
        aiReply('Ce soir : poulet braisé à 5 500 FCFA (contient des arachides).', ['intent' => 'menu_question']),
    ]);
    expect(($this->ask)('Qu’est-ce qu’il y a au menu ?')->handover)->toBeFalse();

    HotelAssistantAgent::fake([aiReply('Le poulet est à 3 000 FCFA.', ['intent' => 'menu_question']), aiReply('Le poulet est à 3 000 FCFA.', ['intent' => 'menu_question'])]);
    expect(($this->ask)('Le prix du poulet ?')->handoverReason)->toBe('guardrail');

    HotelAssistantAgent::fake([aiReply('Votre table est confirmée pour 20 h.', ['intent' => 'table_booking']), aiReply('Votre table est confirmée pour 20 h.', ['intent' => 'table_booking'])]);
    expect(($this->ask)('Une table ?')->handoverReason)->toBe('guardrail');
});

it('books nothing from the practice screen and tells the assistant about outlets', function (): void {
    HotelAssistantAgent::fake([bookTableCall(2), aiReply('Bien noté.', ['intent' => 'table_booking'])]);
    $result = ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Une table')]));

    expect($result->tools[0]['result'])->toContain('"practice":true')
        ->and(($this->in)(fn () => TableBooking::query()->count()))->toBe(0);

    $pack = ($this->in)(fn () => app(KnowledgePack::class)->compile('fr'));
    expect($pack)->toContain('Le Restaurant (code restaurant, restaurant, 20 seats): Dîner 19:00–22:30')
        ->and($pack)->toContain('/m/'.$this->hotel->slug.'/restaurant');
});
