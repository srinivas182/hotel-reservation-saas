<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Arrivals\Models\ArrivalCheck;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\ConversationMode;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\Deposits\Models\MerchantAccount;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\StaffAlerts\Morning\MorningSummary;
use App\Domains\StaffAlerts\Morning\MorningSummaryText;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\StayMessages\StayMessage;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\StarterTemplates;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../WhatsApp/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

const ARRIVAL_GUEST = '2250700000001';
const ARRIVAL_STAFF = '2250700000030';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class]);
    config(['simulation.enabled' => true]);
    $this->travelTo('2026-11-10 08:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(function (): void {
        WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']);
        HotelAssistantSettings::current()->update([
            'stay_messages' => ['reminder' => ['enabled' => true, 'time' => '10:00']],
            'arrival_directions' => ['fr' => 'En face de la Grande Mosquée, portail bleu.'],
            'arrival_tip' => ['fr' => 'Depuis la gare, une moto-taxi coûte environ 500 F CFA.'],
            'reception_closes_at' => '21:00',
            'night_arrival' => ['fr' => 'Sonnez à la cloche du portail : le gardien a votre clé.'],
            'arrival_extras' => ['pickup' => ['enabled' => true, 'price_minor' => 3000], 'early_checkin' => ['enabled' => true, 'price_minor' => null], 'extra_bed' => ['enabled' => false, 'price_minor' => null]],
        ]);
        HotelSettings::current()->update(['latitude' => 9.4580, 'longitude' => -5.6296, 'address_line1' => 'Quartier Résidentiel', 'city' => 'Korhogo', 'check_in_time' => '14:00']);
        MerchantAccount::query()->create(['driver' => 'simulation', 'mode' => 'test', 'credentials' => [], 'active' => true]);
    });
    $this->sent = [];
    Http::fake(['graph.facebook.com/*' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.A'.bin2hex(random_bytes(6))]]]);
    }]);

    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
    ($this->in)(fn () => StaffAlertSettings::query()->create(['user_id' => $this->owner->id, 'alert_phone' => '+'.ARRIVAL_STAFF, 'alerts' => ['handover', 'arrival_cancelling', 'arrival_request', 'arrival_unconfirmed']])->forceFill(['whatsapp_inbound_at' => now()])->save());

    // Tomorrow's guest, who wrote this morning (the 24-hour window is open).
    $this->booking = book($this->hotel, $this->exe, '2026-11-11', '2026-11-13');
    ($this->in)(fn () => $this->booking->guest->forceFill(['whatsapp_opt_in' => true, 'preferred_locale' => 'fr'])->save());
    webhookPost($this, [inbound('wamid.G0', from: ARRIVAL_GUEST)]);
    $this->sent = [];
});

function arrivalTo(object $test, string $who = ARRIVAL_GUEST): array
{
    return array_values(array_filter($test->sent, fn (array $m): bool => ($m['to'] ?? null) === $who));
}

function arrivalBodies(object $test, string $who = ARRIVAL_GUEST): string
{
    return implode("\n", array_map(fn (array $m): string => (string) ($m['text']['body'] ?? $m['interactive']['body']['text'] ?? json_encode($m['template'] ?? $m['location'] ?? [])), arrivalTo($test, $who)));
}

function arrivalTap(object $test, string $id, string $from = ARRIVAL_GUEST, string $kind = 'button_reply'): void
{
    static $n = 0;
    $n++;
    webhookPost($test, [inbound('wamid.TAP'.$n.bin2hex(random_bytes(3)), from: $from, message: ['type' => 'interactive', 'interactive' => ['type' => $kind, $kind => ['id' => $id, 'title' => 'x']]])]);
}

function arrivalCheck(object $test): ArrivalCheck
{
    return ($test->in)(fn () => ArrivalCheck::query()->where('booking_id', $test->booking->id)->sole());
}

function sendArrivalCards(object $test): void
{
    $test->travelTo('2026-11-10 10:05:00');
    $test->artisan('stay-messages:send')->assertSuccessful();
}

it('sends the card with buttons, what is left to pay with a link, then the map pin and directions', function (): void {
    sendArrivalCards($this);

    $messages = arrivalTo($this);
    $card = $messages[0];
    $ids = array_column(array_column($card['interactive']['action']['buttons'], 'reply'), 'id');

    expect($card['type'])->toBe('interactive')
        ->and($card['interactive']['body']['text'])->toContain('Bonjour Awa')->toContain($this->booking->reference)->toContain('14:00')->toContain('Reste à payer')->toContain('/pay/')
        ->and($ids)->toBe(['ga:yes:'.$this->booking->id, 'ga:no:'.$this->booking->id, 'ga:ask:'.$this->booking->id])
        ->and($messages[1]['type'])->toBe('location')
        ->and($messages[1]['location']['latitude'])->toBe(9.458)
        ->and($messages[2]['text']['body'])->toContain('Grande Mosquée')->toContain('moto-taxi')
        ->and(arrivalCheck($this)->card_how)->toBe('text')
        ->and(($this->in)(fn () => DepositLink::query()->sole()->purpose))->toBe('balance')
        ->and(($this->in)(fn () => StayMessage::query()->sole()->how))->toBe('text');

    // Once only.
    $this->artisan('stay-messages:send');
    expect(arrivalTo($this))->toHaveCount(3);
});

it('uses the approved template with button codes when the window is closed', function (): void {
    ($this->in)(function (): void {
        $starter = StarterTemplates::all()['arrival_card'];
        MessageTemplate::query()->create(['starter_key' => 'arrival_card', 'name' => 'arrival_card', 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]);
    });
    $this->travelTo('2026-11-10 10:05:00');
    ($this->in)(fn () => Conversation::query()->update(['last_inbound_at' => now()->subDays(2)]));
    $this->artisan('stay-messages:send')->assertSuccessful();

    $template = arrivalTo($this)[0]['template'];
    $buttons = array_values(array_filter($template['components'], fn (array $c): bool => $c['type'] === 'button'));

    expect($template['name'])->toBe('arrival_card')
        ->and(array_column(array_merge(...array_column($buttons, 'parameters')), 'payload'))->toBe(['ga:yes:'.$this->booking->id, 'ga:no:'.$this->booking->id, 'ga:ask:'.$this->booking->id])
        ->and(arrivalCheck($this)->pin_sent_at)->toBeNull();

    // The tap reopens the window: the pin and the time list follow.
    $this->sent = [];
    webhookPost($this, [inbound('wamid.BTN', from: ARRIVAL_GUEST, message: ['type' => 'button', 'button' => ['payload' => 'ga:yes:'.$this->booking->id, 'text' => 'Je confirme']])]);

    expect(arrivalCheck($this)->confirmed_at)->not->toBeNull()
        ->and(array_column(arrivalTo($this), 'type'))->toContain('location')
        ->and(arrivalBodies($this))->toContain('À quelle heure');
});

it('records the confirmation, the arrival time with night instructions, and the extras asked for', function (): void {
    sendArrivalCards($this);
    $this->sent = [];

    arrivalTap($this, 'ga:yes:'.$this->booking->id);
    $list = collect(arrivalTo($this))->first(fn (array $m): bool => ($m['interactive']['type'] ?? null) === 'list');
    $extras = collect(arrivalTo($this))->first(fn (array $m): bool => ($m['interactive']['type'] ?? null) === 'button');

    expect(arrivalBodies($this))->toContain('Merci Awa')
        ->and(array_column($list['interactive']['action']['sections'][0]['rows'], 'id'))->toContain('ga:eta-night:'.$this->booking->id)
        ->and($extras['interactive']['body']['text'])->toContain('Navette')->toContain('000')
        ->and(array_column(array_column($extras['interactive']['action']['buttons'], 'reply'), 'id'))->toBe(['ga:x-pickup:'.$this->booking->id, 'ga:x-early_checkin:'.$this->booking->id])
        // The pin was already sent with the card.
        ->and(collect(arrivalTo($this))->where('type', 'location')->count())->toBe(0);

    $this->sent = [];
    arrivalTap($this, 'ga:eta-night:'.$this->booking->id, kind: 'list_reply');
    expect(arrivalBodies($this))->toContain('Après 21:00')->toContain('cloche du portail')
        ->and(arrivalCheck($this)->eta_slot)->toBe('night');

    arrivalTap($this, 'ga:x-pickup:'.$this->booking->id);
    arrivalTap($this, 'ga:x-pickup:'.$this->booking->id);
    expect(arrivalCheck($this)->extras)->toBe(['pickup'])
        ->and(arrivalBodies($this, ARRIVAL_STAFF))->toContain('Navette');

    // Today tomorrow: the card shows it.
    asStaffOf($this->hotel, $this->reception)->get(route('hotel.today'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('tomorrow.0.state', 'confirmed')->where('tomorrow.0.eta', 'Après 21:00'));

    // The day of arrival, in the arrivals list and the morning summary.
    $this->travelTo('2026-11-11 07:00:00');
    asStaffOf($this->hotel, $this->reception)->get(route('hotel.today'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('arrivals.0.arrival.state', 'confirmed')->where('arrivals.0.arrival.extras.0', 'Navette (gare, aéroport)'));
    $text = ($this->in)(fn () => MorningSummaryText::message(app(MorningSummary::class)->build(), 'Awa', 'fr', true));
    expect($text)->toContain('1 arrivée confirmée')->toContain('Awa Après 21:00');

    // No assistant reply to button taps: only the guest's first message got one.
    Queue::assertPushedTimes(ReplyToGuest::class, 1);
});

it('asks before treating "not coming", then alerts staff', function (): void {
    sendArrivalCards($this);
    $this->sent = [];

    arrivalTap($this, 'ga:no:'.$this->booking->id);
    $ask = arrivalTo($this)[0];
    expect(array_column(array_column($ask['interactive']['action']['buttons'], 'reply'), 'id'))->toBe(['ga:cancel:'.$this->booking->id, 'ga:yes:'.$this->booking->id])
        ->and(arrivalCheck($this)->declined_at)->toBeNull();

    arrivalTap($this, 'ga:cancel:'.$this->booking->id);

    expect(arrivalCheck($this)->declined_at)->not->toBeNull()
        ->and(arrivalBodies($this))->toContain('La réception annule')
        ->and(arrivalBodies($this, ARRIVAL_STAFF))->toContain('ne vient pas')
        ->and(($this->in)(fn () => Conversation::query()->sole()->needs_attention))->toBeTrue()
        // Staff cancel; the booking itself is untouched.
        ->and(($this->in)(fn () => $this->booking->refresh()->status->value))->toBe('confirmed');
});

it('passes a question to staff', function (): void {
    sendArrivalCards($this);
    arrivalTap($this, 'ga:ask:'.$this->booking->id);

    expect(($this->in)(fn () => Conversation::query()->sole()->mode))->toBe(ConversationMode::Staff)
        ->and(arrivalCheck($this)->question_at)->not->toBeNull();
});

it('ignores a tap from another number, which the assistant answers as usual', function (): void {
    sendArrivalCards($this);
    arrivalTap($this, 'ga:yes:'.$this->booking->id, from: '2250700000099');

    expect(arrivalCheck($this)->confirmed_at)->toBeNull();
    Queue::assertPushedTimes(ReplyToGuest::class, 2);
});

it('flags tomorrow\'s silent guests at 18:00 with one alert, and Today shows who to call', function (): void {
    sendArrivalCards($this);

    $this->travelTo('2026-11-10 17:50:00');
    $this->artisan('arrivals:flag-unconfirmed')->assertSuccessful();
    expect(arrivalCheck($this)->flagged_at)->toBeNull();

    $this->travelTo('2026-11-10 18:05:00');
    $this->artisan('arrivals:flag-unconfirmed')->assertSuccessful();
    $this->artisan('arrivals:flag-unconfirmed')->assertSuccessful();

    expect(arrivalCheck($this)->flagged_at)->not->toBeNull()
        ->and(substr_count(arrivalBodies($this, ARRIVAL_STAFF), 'pas confirmé'))->toBe(1);

    asStaffOf($this->hotel, $this->reception)->get(route('hotel.today'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('tomorrow.0.state', 'waiting')->where('tomorrow.0.flagged', true)->where('tomorrow.0.phone', '+'.ARRIVAL_GUEST));

    // Answering later clears the flag.
    arrivalTap($this, 'ga:yes:'.$this->booking->id);
    expect(arrivalCheck($this)->flagged_at)->toBeNull();
});

it('lets the guest pay the balance before arriving', function (): void {
    sendArrivalCards($this);
    $link = ($this->in)(fn () => DepositLink::query()->sole());

    $this->get(route('deposits.show', $link->token))->assertOk()->assertSee('Payer avant votre arrivée', false)->assertDontSee('Tout le séjour', false);
    $checkout = $this->post(route('deposits.pay', $link->token), ['choice' => 'deposit'])->headers->get('Location');
    $reference = basename((string) parse_url((string) $checkout, PHP_URL_PATH));
    $this->post(route('deposits.simulate.pay', $reference), ['outcome' => 'paid', 'method' => 'wave']);
    $this->get(route('deposits.back', $link->token));

    expect(($this->in)(fn () => DepositLink::query()->sole()->status))->toBe('paid')
        ->and(($this->in)(fn () => $this->booking->refresh()->status->value))->toBe('confirmed')
        ->and(($this->in)(fn () => app(App\Domains\Folio\Folio::class)->balance($this->booking)))->toBeLessThanOrEqual(0);
});

it('lets the owner set directions, extras and the entrance photo; reception cannot', function (): void {
    Storage::fake('local');

    asStaffOf($this->hotel, $this->owner)->get(route('hotel.arrival-settings'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('hasPin', true)->where('settings.reception_closes_at', '21:00'));

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.arrival-settings.update'), [
        'arrival_directions' => ['fr' => '  Derrière la pharmacie  ', 'en' => ''],
        'reception_closes_at' => '22:30',
        'arrival_extras' => ['extra_bed' => ['enabled' => true, 'price_minor' => 5000]],
    ])->assertRedirect();

    $settings = ($this->in)(fn () => HotelAssistantSettings::current()->refresh());
    expect($settings->arrival_directions)->toBe(['fr' => 'Derrière la pharmacie'])
        ->and($settings->reception_closes_at)->toBe('22:30')
        ->and($settings->arrival_extras['extra_bed'])->toBe(['enabled' => true, 'price_minor' => 5000])
        ->and($settings->arrival_extras['pickup']['enabled'])->toBeFalse();

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.arrival-settings.photo'), ['photo' => UploadedFile::fake()->image('entree.jpg', 800, 600)])->assertRedirect();
    $this->get(route('arrivals.entrance', $this->hotel->slug))->assertOk();

    // The card then carries the photo.
    sendArrivalCards($this);
    expect(arrivalTo($this)[0]['interactive']['header']['image']['link'])->toBe(route('arrivals.entrance', $this->hotel->slug));

    asStaffOf($this->hotel, $this->reception)->put(route('hotel.arrival-settings.update'), ['reception_closes_at' => '20:00'])->assertForbidden();
});
