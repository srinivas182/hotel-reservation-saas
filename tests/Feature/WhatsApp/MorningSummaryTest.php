<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Folio\Folio;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\StaffAlerts\Morning\MorningSummaryText;
use App\Domains\StaffAlerts\StaffAlert;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\StarterTemplates;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';

const M_OWNER = '2250700000020';
const M_RECEPTION = '2250700000021';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class]);
    $this->travelTo('2026-11-10 10:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));
    $this->sent = [];
    Http::fake(['graph.facebook.com/*' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.M'.count($this->sent)]]]);
    }]);
    $this->owner = staffMember($this->hotel, SystemRole::Owner, ['first_name' => 'Awa']);
    $this->reception = staffMember($this->hotel, SystemRole::Reception, ['first_name' => 'Kady']);
    $pay = fn (Booking $b, int $amount, string $method) => ($this->in)(fn () => app(Folio::class)->pay($b, Money::ofMinor($amount, 'XOF'), $method, null, $this->owner));

    // Last week (Wednesday 11 November): 40 000 collected; nothing sold for Thursday 12.
    $old = book($this->hotel, $this->exe, '2026-11-25', '2026-11-26', phone: '+2250700000005');
    $this->travelTo('2026-11-11 10:00:00');
    $pay($old, 40000, 'cash');

    // In-house until today, one arrival owing everything, one tentative arrival without its deposit.
    $this->travelTo('2026-11-16 10:00:00');
    $inHouse = book($this->hotel, $this->exe, '2026-11-17', '2026-11-19', phone: '+2250700000002');
    book($this->hotel, $this->exe, '2026-11-19', '2026-11-21', phone: '+2250700000003');
    book($this->hotel, $this->exe, '2026-11-19', '2026-11-20', status: BookingStatus::Tentative, phone: '+2250700000004');
    ($this->in)(fn () => Booking::query()->whereKey($inHouse->id)->update(['status' => BookingStatus::CheckedIn->value]));

    // Yesterday (Wednesday 18): the in-house guest paid in full, cash and Orange Money.
    $this->travelTo('2026-11-18 10:00:00');
    $pay($inHouse, 30000, 'cash');
    $pay($inHouse, 20000, 'orange_money');

    foreach ([[$this->owner, M_OWNER], [$this->reception, M_RECEPTION]] as [$user, $phone]) {
        ($this->in)(fn () => StaffAlertSettings::query()->create(['user_id' => $user->id, 'alert_phone' => '+'.$phone, 'alerts' => [], 'morning_time' => '07:30']));
    }
    // Both tapped a button yesterday: their 24-hour window is open.
    ($this->in)(fn () => StaffAlertSettings::query()->update(['whatsapp_inbound_at' => now()]));
    $this->travelTo('2026-11-19 07:20:00');
});

function morningTo(object $test, string $phone): array
{
    return array_values(array_filter($test->sent, fn (array $m): bool => ($m['to'] ?? null) === $phone));
}

function morningText(array $message): string
{
    return (string) ($message['interactive']['body']['text'] ?? $message['text']['body'] ?? '');
}

function morningTap(object $test, string $phone, string $payload): void
{
    webhookPost($test, [inbound('wamid.MT'.bin2hex(random_bytes(4)), from: $phone, message: ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $payload, 'title' => 'x']]], name: 'Staff')]);
}

it('sends the owner the day ahead at their time, once, with comparisons, the week and what is owed', function (): void {
    $this->artisan('staff:morning-summary')->assertSuccessful();
    expect($this->sent)->toBe([]);

    $this->travelTo('2026-11-19 07:31:00');
    $this->artisan('staff:morning-summary')->assertSuccessful();
    $this->artisan('staff:morning-summary')->assertSuccessful();

    $messages = morningTo($this, M_OWNER);
    expect($messages)->toHaveCount(1);
    $text = morningText($messages[0]);
    expect($text)
        ->toContain('☀️ Bonjour Awa — First Class Lodge, jeudi 19 novembre')
        ->toContain('Aujourd’hui : 2 arrivées · 1 départ · 1 client présent')
        ->toContain('Ce soir : 1/3 chambres (33 %) · +33 points par rapport à la semaine dernière') // addition 1 (the tentative room is held, not sold)
        ->toContain('Hier : encaissé 50')->toContain('Espèces 30')->toContain('Orange Money')->toContain('+25 % par rapport à la semaine dernière')
        ->toContain('💰 2 clients doivent encore') // addition 3
        ->toContain('Les 7 prochaines nuits :')->toContain('▓▓░░░ 33 %')->toContain('Sous 50 %') // addition 2
        ->and(array_column(array_column($messages[0]['interactive']['action']['buttons'], 'reply'), 'title'))->toBe(['Arrivées', 'Présents', 'À encaisser']) // addition 4
        ->and(StaffAlert::query()->withoutGlobalScopes()->where('kind', 'morning_summary')->count())->toBe(2);
});

it('leaves money out for reception, and answers the detail buttons', function (): void {
    $this->travelTo('2026-11-19 07:31:00');
    $this->artisan('staff:morning-summary')->assertSuccessful();

    $reception = morningTo($this, M_RECEPTION)[0];
    expect(morningText($reception))->toContain('2 arrivées')->not->toContain('encaissé')->not->toContain('doivent')->not->toContain('points'); // addition 5
    $buttons = array_column(array_column($reception['interactive']['action']['buttons'], 'reply'), 'id', 'title');

    morningTap($this, M_RECEPTION, $buttons['Arrivées']);
    expect(morningText(collect(morningTo($this, M_RECEPTION))->last()))->toContain('*Arrivées aujourd\'hui*')->toContain('Awa Koné')->toContain('acompte non reçu');

    morningTap($this, M_RECEPTION, $buttons['À encaisser']);
    expect(morningText(collect(morningTo($this, M_RECEPTION))->last()))->toContain('ne sont montrés qu’à ceux qui gèrent');

    $owner = array_column(array_column(morningTo($this, M_OWNER)[0]['interactive']['action']['buttons'], 'reply'), 'id', 'title');
    morningTap($this, M_OWNER, $owner['À encaisser']);
    $list = morningText(collect(morningTo($this, M_OWNER))->last());
    expect($list)->toContain('*À encaisser aujourd’hui*')->toContain('arrivée')->and(substr_count($list, '•'))->toBe(2);

    morningTap($this, M_OWNER, $owner['Présents']);
    expect(morningText(collect(morningTo($this, M_OWNER))->last()))->toContain('17/11 → 19/11');
});

it('follows with the waiting requests as cards to answer', function (): void {
    webhookPost($this, [inbound('wamid.G1', from: '2250700000009', name: 'Moussa')]);
    $conversation = ($this->in)(fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', '2250700000009'))->with('contact.guest')->sole());
    ($this->in)(fn () => app(BookingRequestService::class)->submit($conversation, new StayRequest($this->exe->id, CarbonImmutable::parse('2026-11-24'), CarbonImmutable::parse('2026-11-25'), adults: 2, rooms: 1), 'Moussa Traoré'));
    $this->sent = [];

    $this->travelTo('2026-11-19 07:31:00');
    $this->artisan('staff:morning-summary')->assertSuccessful();

    $owner = morningTo($this, M_OWNER);
    expect(morningText($owner[0]))->toContain('⚠️ 1 demande WhatsApp en attente')
        ->and($owner)->toHaveCount(2)
        ->and(morningText($owner[1]))->toContain('Moussa Traoré')
        ->and(array_column(array_column($owner[1]['interactive']['action']['buttons'], 'reply'), 'title'))->toBe(['Accepter', 'Refuser'])
        ->and(morningTo($this, M_RECEPTION))->toHaveCount(1); // reception answers requests in the dashboard by default
});

it('uses the template with its buttons outside the 24-hour window, and nothing without it', function (): void {
    ($this->in)(fn () => StaffAlertSettings::query()->update(['whatsapp_inbound_at' => null]));
    $this->travelTo('2026-11-19 07:31:00');
    $this->artisan('staff:morning-summary')->assertSuccessful();
    expect($this->sent)->toBe([])
        ->and(StaffAlert::query()->withoutGlobalScopes()->where('kind', 'morning_summary')->value('reason'))->toBe('template_not_approved');

    $starter = StarterTemplates::all()['owner_morning_summary'];
    ($this->in)(fn () => MessageTemplate::query()->create(['starter_key' => 'owner_morning_summary', 'name' => 'owner_morning_summary', 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]));
    $this->travelTo('2026-11-20 07:31:00');
    $this->artisan('staff:morning-summary')->assertSuccessful();

    $template = morningTo($this, M_OWNER)[0]['template'];
    expect($template['name'])->toBe('owner_morning_summary')
        ->and($template['components'][0]['parameters'][2]['text'])->toContain('arrivée')->toContain('encaissé hier')
        ->and(array_slice($template['components'], 1))->toHaveCount(3)
        ->and(morningTo($this, M_RECEPTION)[0]['template']['components'][0]['parameters'][2]['text'])->not->toContain('encaissé');
});

it('sends one short line on a quiet day, or nothing if the person prefers', function (): void {
    ($this->in)(fn () => Booking::query()->update(['status' => BookingStatus::Cancelled->value]));
    ($this->in)(fn () => StaffAlertSettings::query()->where('user_id', $this->reception->id)->update(['morning_quiet_days' => false]));
    $this->travelTo('2026-11-19 07:31:00');
    $this->artisan('staff:morning-summary')->assertSuccessful();

    expect(morningText(morningTo($this, M_OWNER)[0]))->toContain('Journée calme')->not->toContain('Les 7 prochaines nuits') // addition 6
        ->and(morningTo($this, M_RECEPTION))->toBe([])
        ->and(($this->in)(fn () => StaffAlertSettings::query()->where('user_id', $this->reception->id)->value('morning_sent_on')))->not->toBeNull();
});

it('respects the daily message limit', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['daily_alert_cap' => 1]));
    $this->travelTo('2026-11-19 07:31:00');
    $this->artisan('staff:morning-summary')->assertSuccessful();

    expect($this->sent)->toHaveCount(1)
        ->and(StaffAlert::query()->withoutGlobalScopes()->where('reason', 'daily_cap')->count())->toBe(1);
});

it('lets each person choose their morning summary', function (): void {
    $viewer = staffMember($this->hotel, SystemRole::ReadOnly);
    asStaffOf($this->hotel, $viewer)->get(route('hotel.my-alerts'))->assertInertia(fn ($page) => $page->where('settings.morning_on', false)->where('settings.morning_time', '07:30')->where('morningMoney', false));
    asStaffOf($this->hotel, $viewer)->put(route('hotel.my-alerts.update'), ['alert_phone' => '+2250700000030', 'alerts' => [], 'morning_on' => true, 'morning_time' => '06:45', 'morning_quiet_days' => false])->assertSessionHasNoErrors();

    $settings = ($this->in)(fn () => StaffAlertSettings::query()->where('user_id', $viewer->id)->sole());
    expect($settings->morning_time)->toBe('06:45')->and($settings->morning_quiet_days)->toBeFalse();

    asStaffOf($this->hotel, $viewer)->put(route('hotel.my-alerts.update'), ['alert_phone' => '+2250700000030', 'alerts' => [], 'morning_on' => false]);
    expect(($this->in)(fn () => StaffAlertSettings::query()->where('user_id', $viewer->id)->sole()->morning_time))->toBeNull();
});

it('draws the week as bars', function (): void {
    expect(MorningSummaryText::bar(0))->toBe('░░░░░')
        ->and(MorningSummaryText::bar(80))->toBe('▓▓▓▓░')
        ->and(MorningSummaryText::bar(100))->toBe('▓▓▓▓▓')
        ->and(MorningSummaryText::bar(140))->toBe('▓▓▓▓▓');
});
