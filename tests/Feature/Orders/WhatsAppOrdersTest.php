<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\Assistant\Engine\ToolContext;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\OrderSource;
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
    $this->travelTo('2026-11-13 20:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    [$this->outlet, $this->poulet, $this->flag] = roomService($this->hotel);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));
    $this->sent = [];
    Http::fake(['graph.facebook.com/v23.0/5550001/messages' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.O'.count($this->sent)]]]);
    }]);
    $this->stay = checkedIn($this->hotel, $this->exe, '+2250700000001');
    webhookPost($this, [inbound('wamid.G1', from: '2250700000001', name: 'Awa')]);
    $this->conversation = ($this->in)(fn () => Conversation::query()->sole());
    $this->ask = fn (string $text, ?Conversation $conversation = null) => ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest($text)], ($conversation ?? $this->conversation)->id));
    $this->chef = staffMember($this->hotel, SystemRole::RestaurantBar);
});

function orderCall(bool $agreed, string $delivery = 'room', string $payment = 'room_charge', array $items = [['dish' => 'Poulet braisé', 'quantity' => 2], ['dish' => 'bière flag', 'quantity' => 1]]): ToolCall
{
    return new ToolCall('o'.(int) $agreed, 'place_order', ['items' => $items, 'delivery' => $delivery, 'payment' => $payment, 'guest_agreed' => $agreed, 'location' => $delivery === 'outlet' ? 'Piscine' : null]);
}

function guestTexts(object $test): array
{
    return ($test->in)(fn () => Message::query()->where('source', MessageSource::System->value)->orderBy('id')->pluck('body')->all());
}

it('repeats the order and total before sending anything', function (): void {
    HotelAssistantAgent::fake([orderCall(false), aiReply('Deux poulets braisés et une Flag en chambre : 11 000 FCFA sur la note. Je confirme ?', ['intent' => 'food_order'])]);

    $result = ($this->ask)('Deux poulets et une Flag en chambre, sur la note');

    expect($result->handover)->toBeFalse()
        ->and($result->tools[0]['result'])->toContain('"preview"')
        ->and(($this->in)(fn () => Order::query()->count()))->toBe(0);
});

it('sends the order to the kitchen once the guest agrees, and alerts the team', function (): void {
    ($this->in)(fn () => StaffAlertSettings::query()->create(['user_id' => $this->chef->id, 'alert_phone' => '+2250700000050', 'alerts' => ['new_order']]));
    ($this->in)(function (): void {
        $starter = StarterTemplates::all()['staff_alert'];
        MessageTemplate::query()->create(['starter_key' => 'staff_alert', 'name' => 'staff_alert', 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]);
    });
    HotelAssistantAgent::fake([orderCall(true), aiReply('C’est transmis à la cuisine, qui vous indiquera le délai.', ['intent' => 'food_order'])]);

    ($this->ask)('Oui, je confirme');

    $order = ($this->in)(fn () => Order::query()->with('lines')->sole());
    expect($order->source)->toBe(OrderSource::WhatsApp)
        ->and($order->booking_id)->toBe($this->stay->id)
        ->and($order->room_number)->toBe(App\Domains\Orders\Stays::roomNumbers($this->stay)[0])
        ->and($order->total_minor)->toBe(11000)
        ->and(collect($this->sent)->pluck('to')->all())->toContain('2250700000050');
});

it('offers what can be ordered when a dish is unknown', function (): void {
    HotelAssistantAgent::fake([orderCall(false, items: [['dish' => 'Pizza', 'quantity' => 1]]), aiReply('Nous n’avons pas de pizza. Je peux vous proposer le poulet braisé.', ['intent' => 'food_order'])]);

    $result = ($this->ask)('Une pizza en chambre');

    expect($result->tools[0]['result'])->toContain('unknown_dishes')->toContain('Poulet braisé');
});

it('keeps room delivery and the room bill for guests staying, and respects the hotel\'s choice for others', function (): void {
    webhookPost($this, [inbound('wamid.X1', from: '2250700000099', name: 'Kofi')]);
    $outsider = ($this->in)(fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', '2250700000099'))->sole());

    HotelAssistantAgent::fake([orderCall(true), aiReply('Je vous propose un retrait sur place.', ['intent' => 'food_order'])]);
    expect(($this->ask)('Livrez en chambre', $outsider)->tools[0]['result'])->toContain('not_staying');

    HotelAssistantAgent::fake([orderCall(true, 'outlet', 'on_delivery'), aiReply('Transmis.', ['intent' => 'food_order'])]);
    ($this->ask)('Au bord de la piscine, je paie sur place', $outsider);
    expect(($this->in)(fn () => Order::query()->sole()->booking_id))->toBeNull();

    ($this->in)(fn () => RestaurantSettings::current()->update(['accept_outside_orders' => false]));
    HotelAssistantAgent::fake([orderCall(true, 'outlet', 'on_delivery'), aiReply('Désolé.', ['intent' => 'food_order'])]);
    expect(($this->ask)('Encore une', $outsider)->tools[0]['result'])->toContain('outside_guests');
});

it('says when the room bill limit is reached so the guest can pay on delivery', function (): void {
    ($this->in)(fn () => RestaurantSettings::current()->update(['room_charge_order_limit_minor' => 5000]));
    HotelAssistantAgent::fake([orderCall(true), aiReply('Cette commande dépasse la limite de la note, vous pouvez payer à la livraison.', ['intent' => 'food_order'])]);

    expect(($this->ask)('Oui')->tools[0]['result'])->toContain('room_charge_limit');
});

it('tells the guest when the kitchen accepts, declines or has the order ready', function (string $status, ?int $eta, string $delivery, string $expected): void {
    HotelAssistantAgent::fake([orderCall(true, $delivery, $delivery === 'room' ? 'room_charge' : 'on_delivery'), aiReply('Transmis.', ['intent' => 'food_order'])]);
    ($this->ask)('Oui');
    $order = ($this->in)(fn () => Order::query()->sole());

    if ($status === 'ready') {
        asStaffOf($this->hotel, $this->chef)->post(route('hotel.restaurant.orders.status', $order), ['status' => 'accepted', 'eta_minutes' => 15]);
    }
    asStaffOf($this->hotel, $this->chef)->post(route('hotel.restaurant.orders.status', $order), array_filter(['status' => $status, 'eta_minutes' => $eta, 'reason' => $status === 'declined' ? 'Cuisine fermée' : null]))->assertSessionHasNoErrors();

    expect(collect(guestTexts($this))->last() ?? '')->toContain($expected);
})->with([
    'accepted' => ['accepted', 20, 'room', '20 minutes'],
    'declined' => ['declined', null, 'room', 'Cuisine fermée'],
    'ready at the outlet' => ['ready', null, 'outlet', 'est prête'],
]);

it('shows the room bill only to a number that belongs to the stay', function (): void {
    HotelAssistantAgent::fake([new ToolCall('b1', 'get_my_bill', []), aiReply('Voici votre note.', ['intent' => 'bill_question'])]);
    $mine = ($this->ask)('Ma facture ?');
    expect($mine->tools[0]['result'])->toContain('"found":true')->toContain($this->stay->reference);

    webhookPost($this, [inbound('wamid.X2', from: '2250700000098', name: 'Inconnu')]);
    $other = ($this->in)(fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', '2250700000098'))->sole());
    HotelAssistantAgent::fake([new ToolCall('b2', 'get_my_bill', []), aiReply('Je ne trouve pas de séjour pour ce numéro, la réception peut vous aider.', ['intent' => 'bill_question'])]);
    expect(($this->ask)('La facture de la chambre EXE1', $other)->tools[0]['result'])->toContain('"found":false')->not->toContain($this->stay->reference);

    $practice = ($this->in)(fn () => (new App\Domains\Assistant\Engine\Tools\GetMyBill(new ToolContext('fr')))->handle(new Laravel\Ai\Tools\Request([])));
    expect((string) $practice)->toContain('"found":false');
});

it('never lets the assistant say an order is accepted', function (): void {
    HotelAssistantAgent::fake([aiReply('Votre commande est acceptée, prête dans 10 minutes.', ['intent' => 'food_order']), aiReply('Votre commande est acceptée.', ['intent' => 'food_order'])]);

    expect(($this->ask)('Ma commande ?')->handoverReason)->toBe('guardrail');
});

it('ships test-suite cases that only expect tools the assistant has', function (): void {
    $tools = collect((new HotelAssistantAgent('x', [], new ToolContext('fr')))->tools())->map(fn ($t) => $t->name())->all();
    $suite = json_decode((string) file_get_contents(resource_path('ai-evals/assistant.json')), true);
    $expected = collect($suite['cases'])->flatMap(fn (array $c) => $c['expect']['tools'] ?? [])->unique()->values()->all();

    expect(array_diff($expected, $tools))->toBe([])
        ->and(collect($suite['cases'])->count())->toBe(100);
});

it('runs a stay: order, kitchen, delivery, the bill on WhatsApp, payment and check-out', function (): void {
    HotelAssistantAgent::fake([
        orderCall(false), aiReply('2 poulets braisés et 1 Flag : 11 000 FCFA sur la note. Je confirme ?', ['intent' => 'food_order']),
        orderCall(true), aiReply('C’est transmis à la cuisine.', ['intent' => 'food_order']),
        new ToolCall('b1', 'get_my_bill', []), aiReply('Votre solde est indiqué ci-dessus.', ['intent' => 'bill_question']),
    ]);

    ($this->ask)('Deux poulets braisés et une Flag en chambre, sur la note');
    ($this->ask)('Oui');
    $order = ($this->in)(fn () => Order::query()->sole());

    foreach ([['accepted', 20], ['ready', null], ['delivered', null]] as [$status, $eta]) {
        asStaffOf($this->hotel, $this->chef)->post(route('hotel.restaurant.orders.status', $order), array_filter(['status' => $status, 'eta_minutes' => $eta]))->assertSessionHasNoErrors();
    }

    $bill = ($this->ask)('Ma facture ?')->tools[0]['result'];
    $balance = ($this->in)(fn () => app(App\Domains\Folio\Folio::class)->balance($this->stay->fresh()));
    expect($bill)->toContain($order->reference)->and($balance)->toBe($this->stay->total_minor + 11000);

    $reception = staffMember($this->hotel, SystemRole::Reception);
    asStaffOf($this->hotel, $reception)->post(route('hotel.bookings.payments.store', $this->stay), ['amount' => $balance, 'method' => 'orange_money', 'reference' => 'OM-778'])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $reception)->post(route('hotel.bookings.check-out', $this->stay))->assertSessionHasNoErrors();

    expect(($this->in)(fn () => $this->stay->fresh()->status))->toBe(BookingStatus::CheckedOut)
        ->and(guestTexts($this)[0])->toContain('20 minutes');
});
