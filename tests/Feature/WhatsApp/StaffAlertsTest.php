<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Conversations\ConversationNeedsStaff;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\WhatsAppContact;
use App\Domains\Messaging\Usage\UsageRecord;
use App\Domains\StaffAlerts\Mail\OwnerSummaryMail;
use App\Domains\StaffAlerts\StaffAlert;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\StarterTemplates;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class]);
    $this->travelTo('2026-11-12 10:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));
    $this->sent = [];
    Http::fake(['graph.facebook.com/v23.0/5550001/messages' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.A'.count($this->sent)]]]);
    }]);
    foreach (['staff_alert', 'owner_daily_summary'] as $name) {
        $starter = StarterTemplates::all()[$name];
        ($this->in)(fn () => MessageTemplate::query()->create(['starter_key' => $name, 'name' => $name, 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]));
    }
    webhookPost($this, [inbound('wamid.IN1', name: 'Awa')]);
    $this->conversation = ($this->in)(fn () => Conversation::query()->sole());
});

function alertsFor(object $test, User $user, string $phone, array $alerts): void
{
    ($test->in)(fn () => StaffAlertSettings::query()->create(['user_id' => $user->id, 'alert_phone' => $phone, 'alerts' => $alerts]));
}

it('alerts only the people who asked for it and can act on it', function (): void {
    $amara = staffMember($this->hotel, SystemRole::Reception);
    $kofi = staffMember($this->hotel, SystemRole::Reception);
    $viewer = staffMember($this->hotel, SystemRole::ReadOnly);
    alertsFor($this, $amara, '+2250700000010', ['handover']);
    alertsFor($this, $kofi, '+2250700000011', ['new_request']);
    alertsFor($this, $viewer, '+2250700000012', ['handover']);

    ($this->in)(fn () => ConversationNeedsStaff::dispatch($this->conversation->id, 'handover'));

    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['to'])->toBe('2250700000010')
        ->and($this->sent[0]['template']['name'])->toBe('staff_alert')
        ->and($this->sent[0]['template']['components'][0]['parameters'])->toHaveCount(3)
        ->and(($this->in)(fn () => UsageRecord::query()->where('meter', 'whatsapp.template.utility')->count()))->toBe(1);
});

it('sends free text to staff who wrote to the hotel number in the last 24 hours', function (): void {
    $amara = staffMember($this->hotel, SystemRole::Reception);
    alertsFor($this, $amara, '+2250700000010', ['handover']);
    ($this->in)(fn () => WhatsAppContact::query()->create(['wa_id' => '2250700000010', 'last_inbound_at' => now()->subHour()]));

    ($this->in)(fn () => ConversationNeedsStaff::dispatch($this->conversation->id, 'handover'));

    expect($this->sent[0]['type'])->toBe('text')->and($this->sent[0]['text']['body'])->toContain('First Class Lodge');
});

it('does not repeat an alert within 10 minutes and stops at the daily maximum', function (): void {
    $amara = staffMember($this->hotel, SystemRole::Reception);
    alertsFor($this, $amara, '+2250700000010', ['handover', 'guest_waiting']);
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['daily_alert_cap' => 2]));

    ($this->in)(fn () => ConversationNeedsStaff::dispatch($this->conversation->id, 'handover'));
    ($this->in)(fn () => ConversationNeedsStaff::dispatch($this->conversation->id, 'guest_waiting'));
    expect($this->sent)->toHaveCount(1);

    $this->travel(11)->minutes();
    ($this->in)(fn () => ConversationNeedsStaff::dispatch($this->conversation->id, 'guest_waiting'));
    $this->travel(11)->minutes();
    ($this->in)(fn () => ConversationNeedsStaff::dispatch($this->conversation->id, 'guest_waiting'));

    expect($this->sent)->toHaveCount(2)
        ->and(($this->in)(fn () => StaffAlert::query()->where('reason', 'daily_cap')->count()))->toBe(1);
});

it('records that an alert could not be sent when its template is not approved', function (): void {
    $amara = staffMember($this->hotel, SystemRole::Reception);
    alertsFor($this, $amara, '+2250700000010', ['handover']);
    ($this->in)(fn () => MessageTemplate::query()->where('name', 'staff_alert')->update(['status' => 'pending']));

    ($this->in)(fn () => ConversationNeedsStaff::dispatch($this->conversation->id, 'handover'));

    expect($this->sent)->toBe([])
        ->and(($this->in)(fn () => StaffAlert::query()->sole()->reason))->toBe('template_not_approved');
});

it('alerts reception about new WhatsApp booking requests', function (): void {
    $amara = staffMember($this->hotel, SystemRole::Reception);
    alertsFor($this, $amara, '+2250700000010', ['new_request']);

    ($this->in)(fn () => app(App\Domains\BookingRequests\BookingRequestService::class)->submit(
        $this->conversation->load('contact.guest'),
        new App\Domains\Rates\Quote\StayRequest($this->exe->id, Carbon\CarbonImmutable::parse('2026-11-20'), Carbon\CarbonImmutable::parse('2026-11-21'), adults: 2),
        'Awa Koné',
    ));

    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['template']['components'][0]['parameters'][2]['text'])->toContain('Awa Koné');
});

it('sends owners their daily summary once, at their chosen time', function (): void {
    $owner = staffMember($this->hotel, SystemRole::Owner);
    $manager = staffMember($this->hotel, SystemRole::Manager);
    ($this->in)(function () use ($owner, $manager): void {
        StaffAlertSettings::query()->create(['user_id' => $owner->id, 'alert_phone' => '+2250700000020', 'summary_time' => '21:00', 'summary_channels' => ['whatsapp', 'email']]);
        StaffAlertSettings::query()->create(['user_id' => $manager->id, 'alert_phone' => '+2250700000021', 'summary_time' => '21:00', 'summary_channels' => ['whatsapp']]);
    });
    book($this->hotel, $this->exe, '2026-11-12', '2026-11-14', phone: '+2250700000099');

    $this->travelTo('2026-11-12 20:55:00');
    $this->artisan('owners:daily-summary');
    expect($this->sent)->toBe([]);

    $this->travelTo('2026-11-12 21:05:00');
    $this->artisan('owners:daily-summary');
    $this->artisan('owners:daily-summary');

    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['to'])->toBe('2250700000020')
        ->and($this->sent[0]['template']['name'])->toBe('owner_daily_summary')
        ->and(array_column($this->sent[0]['template']['components'][0]['parameters'], 'text'))->toBe([
            'First Class Lodge', '12 novembre 2026', '1', '0', '1/3', '1', MessageTemplate::cleanValue(App\Domains\Finance\Money\MoneyFormatter::format(Brick\Money\Money::of(50000, 'XOF'), 'fr')), '0/0/0', '0', 'US$ 0.00',
        ]);
    Mail::assertQueued(OwnerSummaryMail::class, 1);
});

it('saves each person\'s alert settings, keeping only alerts they may receive', function (): void {
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->put(route('hotel.my-alerts.update'), [
        'alert_phone' => '+225 07 00 00 00 10', 'alerts' => ['handover', 'new_request'], 'summary_time' => '21:00', 'summary_channels' => ['email'],
    ])->assertSessionHasNoErrors();

    $settings = ($this->in)(fn () => StaffAlertSettings::query()->sole());
    expect($settings->alert_phone)->toBe('+2250700000010')
        ->and($settings->alerts)->toBe(['handover', 'new_request'])
        ->and($settings->summary_channels)->toBe([]);

    asStaffOf($this->hotel, $reception)->post(route('hotel.my-alerts.test'))->assertSessionHas('inertia.flash_data');
    expect($this->sent)->toHaveCount(1);

    $owner = staffMember($this->hotel, SystemRole::Owner);
    asStaffOf($this->hotel, $owner)->get(route('hotel.my-alerts'))->assertInertia(fn ($page) => $page->where('isOwner', true)->has('team', 1));
    asStaffOf($this->hotel, $reception)->get(route('hotel.my-alerts'))->assertInertia(fn ($page) => $page->where('isOwner', false)->has('team', 0));
});

it('keeps zero and accented text intact in template values', function (): void {
    expect(MessageTemplate::cleanValue('0'))->toBe('0')
        ->and(MessageTemplate::cleanValue(''))->toBe('-')
        ->and(MessageTemplate::cleanValue("Exécutive\n  du 12"))->toBe('Exécutive du 12')
        ->and(MessageTemplate::cleanValue("50\u{202F}000\u{00A0}F CFA"))->toBe('50 000 F CFA');
});
