<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\StaffActions\ActionToken;
use App\Domains\StaffActions\Models\StaffAction;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\StarterTemplates;
use App\Domains\WhatsApp\Templates\TemplateService;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';

const OWNER_PHONE = '2250700000010';
const MANAGER_PHONE = '2250700000011';
const RECEPTION_PHONE = '2250700000012';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class]);
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));
    $this->sent = [];
    Http::fake(['graph.facebook.com/*' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.OUT'.count($this->sent)]]]);
    }]);
    foreach (['staff_alert', 'booking_confirmed', 'booking_declined'] as $name) {
        $starter = StarterTemplates::all()[$name];
        ($this->in)(fn () => MessageTemplate::query()->create(['starter_key' => $name, 'name' => $name, 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]));
    }

    $this->owner = staffMember($this->hotel, SystemRole::Owner, ['first_name' => 'Awa', 'last_name' => 'Owner']);
    $this->manager = staffMember($this->hotel, SystemRole::Manager, ['first_name' => 'Kofi', 'last_name' => 'Manager']);
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
    foreach ([[$this->owner, OWNER_PHONE], [$this->manager, MANAGER_PHONE], [$this->reception, RECEPTION_PHONE]] as [$user, $phone]) {
        ($this->in)(fn () => StaffAlertSettings::query()->create(['user_id' => $user->id, 'alert_phone' => '+'.$phone, 'alerts' => ['new_request']]));
    }

    webhookPost($this, [inbound('wamid.G1', from: '2250700000001', name: 'Awa Koné')]);
    $this->conversation = ($this->in)(fn () => Conversation::query()->with('contact.guest')->sole());
    $this->sent = [];
});

function cardRequest(object $test): BookingRequest
{
    return ($test->in)(fn () => app(BookingRequestService::class)->submit(
        $test->conversation,
        new StayRequest($test->exe->id, CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-14'), adults: 2, rooms: 1),
        'Awa Koné',
    ));
}

/** The messages sent to one phone. */
function sentToPhone(object $test, string $phone): array
{
    return array_values(array_filter($test->sent, fn (array $m): bool => ($m['to'] ?? null) === $phone));
}

/** The button codes of the last message with buttons sent to a phone. */
function buttonsFor(object $test, string $phone): array
{
    foreach (array_reverse(sentToPhone($test, $phone)) as $message) {
        if (($message['type'] ?? null) === 'interactive') {
            return array_column(array_column($message['interactive']['action']['buttons'], 'reply'), 'id', 'title');
        }
        if (($message['type'] ?? null) === 'template' && isset($message['template']['components'][1])) {
            return array_map(fn (array $c): string => $c['parameters'][0]['payload'], array_slice($message['template']['components'], 1));
        }
    }

    return [];
}

function lastTextFor(object $test, string $phone): string
{
    $last = sentToPhone($test, $phone);
    $message = end($last) ?: [];

    return (string) ($message['text']['body'] ?? $message['interactive']['body']['text'] ?? '');
}

function staffTap(object $test, string $phone, string $payload, ?string $wamid = null): void
{
    webhookPost($test, [inbound($wamid ?? 'wamid.TAP'.bin2hex(random_bytes(4)), from: $phone, message: ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $payload, 'title' => 'x']]], name: 'Staff')]);
}

/** Staff who tapped recently have their window open: buttons come as free messages. */
function openStaffWindow(object $test, string $phone): void
{
    ($test->in)(fn () => StaffAlertSettings::query()->where('alert_phone', '+'.$phone)->update(['whatsapp_inbound_at' => now()]));
}

function runDueDecisions(object $test): void
{
    $test->artisan('staff-actions:run-due')->assertSuccessful();
}

it('sends the request card with Accept and Decline to those who answer from WhatsApp', function (): void {
    openStaffWindow($this, OWNER_PHONE);
    $past = book($this->hotel, $this->exe, '2026-12-20', '2026-12-22');
    ($this->in)(fn () => Booking::query()->whereKey($past->id)->update(['status' => BookingStatus::CheckedOut->value, 'guest_id' => $this->conversation->contact->guest->id]));

    cardRequest($this);

    $card = sentToPhone($this, OWNER_PHONE)[0];
    expect($card['type'])->toBe('interactive')
        ->and($card['interactive']['body']['text'])->toContain('Awa Koné')
        ->toContain('client fidèle (séjour 2)') // addition 4
        ->toContain('il reste 2 chambres Exécutive') // addition 3
        ->toContain('2 nuits')
        ->toContain('50')
        ->and(array_keys(buttonsFor($this, OWNER_PHONE)))->toBe(['Accepter', 'Refuser'])
        // The manager's window is closed and the button template is not approved yet: the plain alert.
        ->and(sentToPhone($this, MANAGER_PHONE)[0]['template']['name'])->toBe('staff_alert')
        // Reception answers in the dashboard by default.
        ->and(sentToPhone($this, RECEPTION_PHONE)[0]['template']['name'])->toBe('staff_alert');

    // With the button template approved, the template carries the buttons outside the window.
    ($this->in)(fn () => MessageTemplate::query()->create(['starter_key' => 'booking_request_decision', 'name' => 'booking_request_decision', 'language' => 'fr', 'category' => 'UTILITY', 'body' => StarterTemplates::all()['booking_request_decision']['bodies']['fr'], 'examples' => ['a', 'b'], 'status' => TemplateStatus::Approved]));
    $this->sent = [];
    $this->travel(11)->minutes();
    ($this->in)(fn () => BookingRequest::query()->update(['status' => BookingRequestStatus::Withdrawn]));
    cardRequest($this);

    $template = sentToPhone($this, MANAGER_PHONE)[0]['template'];
    expect($template['name'])->toBe('booking_request_decision')
        ->and($template['components'][1])->toMatchArray(['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0'])
        ->and(strlen($template['components'][1]['parameters'][0]['payload']))->toBeLessThanOrEqual(128);
});

it('accepts after the undo minute, tells the guest and the staff member, and never opens a guest conversation', function (): void {
    openStaffWindow($this, OWNER_PHONE);
    $request = cardRequest($this);
    $accept = buttonsFor($this, OWNER_PHONE)['Accepter'];

    staffTap($this, OWNER_PHONE, $accept);

    expect(lastTextFor($this, OWNER_PHONE))->toContain('Acceptation de la demande de Awa Koné')
        ->and(array_keys(buttonsFor($this, OWNER_PHONE)))->toBe(['Annuler'])
        ->and(($this->in)(fn () => $request->fresh()->status))->toBe(BookingRequestStatus::Pending) // nothing yet: one minute to undo
        ->and(($this->in)(fn () => Conversation::query()->count()))->toBe(1)
        ->and(($this->in)(fn () => Message::query()->where('direction', 'in')->count()))->toBe(1); // only the guest's own message

    $this->travel(91)->seconds();
    runDueDecisions($this);

    $booking = ($this->in)(fn () => Booking::query()->sole());
    expect(($this->in)(fn () => $request->fresh()->status))->toBe(BookingRequestStatus::Approved)
        ->and(($this->in)(fn () => $request->fresh()->decided_by))->toBe($this->owner->id)
        ->and(lastTextFor($this, OWNER_PHONE))->toContain($booking->reference)->toContain('Le client a été informé')
        ->and(sentToPhone($this, '2250700000001'))->not->toBeEmpty() // the guest was told
        ->and(AuditLog::query()->where('event', 'booking_requests.decided_on_whatsapp')->count())->toBe(1);

    // Running again changes nothing.
    runDueDecisions($this);
    expect(($this->in)(fn () => Booking::query()->count()))->toBe(1);
});

it('lets the person undo within the minute, and says when it is too late', function (): void {
    openStaffWindow($this, OWNER_PHONE);
    $request = cardRequest($this);
    staffTap($this, OWNER_PHONE, buttonsFor($this, OWNER_PHONE)['Accepter']);
    $undo = buttonsFor($this, OWNER_PHONE)['Annuler'];

    staffTap($this, OWNER_PHONE, $undo);
    $this->travel(2)->minutes();
    runDueDecisions($this);

    expect(lastTextFor($this, OWNER_PHONE))->toContain('Annulé')
        ->and(($this->in)(fn () => $request->fresh()->status))->toBe(BookingRequestStatus::Pending)
        ->and(($this->in)(fn () => Booking::query()->count()))->toBe(0)
        ->and(($this->in)(fn () => StaffAction::query()->sole()->status))->toBe('undone');

    // Accept again, let it run, then try to undo: too late (and the code has expired anyway).
    staffTap($this, OWNER_PHONE, ActionToken::make($this->hotel->id, 'br_accept', $request->id, $this->owner->id, now()->addHour()));
    $this->travel(91)->seconds();
    runDueDecisions($this);
    staffTap($this, OWNER_PHONE, ActionToken::make($this->hotel->id, 'undo', ($this->in)(fn () => StaffAction::query()->where('status', 'done')->sole()->id), $this->owner->id, now()->addMinute()));

    expect(lastTextFor($this, OWNER_PHONE))->toContain('Trop tard')
        ->and(($this->in)(fn () => Booking::query()->count()))->toBe(1);
});

it('asks why when declining, then declines with that reason', function (): void {
    openStaffWindow($this, OWNER_PHONE);
    $request = cardRequest($this);

    staffTap($this, OWNER_PHONE, buttonsFor($this, OWNER_PHONE)['Refuser']);
    $reasons = buttonsFor($this, OWNER_PHONE);
    expect(array_keys($reasons))->toBe(['Nous sommes complets', 'Dates impossibles', 'Autre raison']);

    staffTap($this, OWNER_PHONE, $reasons['Nous sommes complets']);
    $this->travel(91)->seconds();
    runDueDecisions($this);

    expect(($this->in)(fn () => $request->fresh()->status))->toBe(BookingRequestStatus::Declined)
        ->and(($this->in)(fn () => $request->fresh()->decline_reason))->toBe('full')
        ->and(lastTextFor($this, OWNER_PHONE))->toContain('Refusée');
});

it('tells a second person who already handled the request', function (): void {
    openStaffWindow($this, OWNER_PHONE);
    openStaffWindow($this, MANAGER_PHONE);
    $request = cardRequest($this);
    staffTap($this, OWNER_PHONE, buttonsFor($this, OWNER_PHONE)['Accepter']);

    staffTap($this, MANAGER_PHONE, buttonsFor($this, MANAGER_PHONE)['Refuser']);
    expect(lastTextFor($this, MANAGER_PHONE))->toContain('Awa Owner est déjà en train d’accepter');

    $this->travel(91)->seconds();
    runDueDecisions($this);
    staffTap($this, MANAGER_PHONE, ActionToken::make($this->hotel->id, 'br_accept', $request->id, $this->manager->id, now()->addHour()));
    expect(lastTextFor($this, MANAGER_PHONE))->toContain('Déjà acceptée par Awa Owner à');
});

it('reports a decision made in the dashboard during the undo minute', function (): void {
    openStaffWindow($this, OWNER_PHONE);
    $request = cardRequest($this);
    staffTap($this, OWNER_PHONE, buttonsFor($this, OWNER_PHONE)['Accepter']);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.decline', $request), ['reason' => 'full'])->assertSessionHasNoErrors();
    $this->travel(91)->seconds();
    runDueDecisions($this);

    expect(lastTextFor($this, OWNER_PHONE))->toContain('Déjà refusée par')
        ->and(($this->in)(fn () => StaffAction::query()->sole()->status))->toBe('failed')
        ->and(($this->in)(fn () => Booking::query()->count()))->toBe(0);
});

it('sends a changed price to the dashboard instead of accepting it', function (): void {
    openStaffWindow($this, OWNER_PHONE);
    $request = cardRequest($this);
    ($this->in)(fn () => CategoryRate::query()->update(['base_minor' => 30000]));

    staffTap($this, OWNER_PHONE, buttonsFor($this, OWNER_PHONE)['Accepter']);

    expect(lastTextFor($this, OWNER_PHONE))->toContain('Le prix est maintenant')
        ->and(($this->in)(fn () => StaffAction::query()->count()))->toBe(0)
        ->and(($this->in)(fn () => $request->fresh()->status))->toBe(BookingRequestStatus::Pending);
});

it('only accepts signed buttons from the phone they were sent to, once', function (): void {
    openStaffWindow($this, OWNER_PHONE);
    $request = cardRequest($this);
    $accept = buttonsFor($this, OWNER_PHONE)['Accepter'];

    // Someone else's phone, a changed code, another hotel's code: refused in silence, nothing created.
    staffTap($this, '2250799999999', $accept);
    staffTap($this, MANAGER_PHONE, $accept);
    staffTap($this, OWNER_PHONE, substr($accept, 0, -2).'xx');
    $other = Hotel::factory()->create();
    staffTap($this, OWNER_PHONE, ActionToken::make($other->id, 'br_accept', $request->id, $this->owner->id, now()->addHour()));

    expect(($this->in)(fn () => StaffAction::query()->count()))->toBe(0)
        ->and(AuditLog::query()->where('event', 'staff_actions.refused')->count())->toBe(4)
        ->and(($this->in)(fn () => Conversation::query()->count()))->toBe(1);

    // The same tap delivered twice by Meta has one effect.
    staffTap($this, OWNER_PHONE, $accept, 'wamid.SAME');
    staffTap($this, OWNER_PHONE, $accept, 'wamid.SAME');
    expect(($this->in)(fn () => StaffAction::query()->count()))->toBe(1);

    // An expired code says so; someone removed from the hotel is refused.
    staffTap($this, OWNER_PHONE, ActionToken::make($this->hotel->id, 'br_accept', $request->id, $this->owner->id, now()->subMinute()));
    expect(lastTextFor($this, OWNER_PHONE))->toContain('Ce bouton a expiré');

    $this->hotel->members()->updateExistingPivot($this->manager->id, ['status' => 'suspended']);
    staffTap($this, MANAGER_PHONE, ActionToken::make($this->hotel->id, 'br_accept', $request->id, $this->manager->id, now()->addHour()));
    expect(AuditLog::query()->where('event', 'staff_actions.refused')->count())->toBe(5);
});

it('lets each person choose, and keeps reception in the dashboard by default', function (): void {
    openStaffWindow($this, RECEPTION_PHONE);
    $request = cardRequest($this);
    staffTap($this, RECEPTION_PHONE, ActionToken::make($this->hotel->id, 'br_accept', $request->id, $this->reception->id, now()->addHour()));
    expect(lastTextFor($this, RECEPTION_PHONE))->toContain('est désactivé pour vous');

    asStaffOf($this->hotel, $this->reception)->get(route('hotel.my-alerts'))->assertInertia(fn ($page) => $page->where('settings.approve_by_whatsapp', false)->where('canDecide', true));
    asStaffOf($this->hotel, $this->reception)->put(route('hotel.my-alerts.update'), ['alert_phone' => '+'.RECEPTION_PHONE, 'alerts' => ['new_request'], 'approve_by_whatsapp' => true])->assertSessionHasNoErrors();
    expect(($this->in)(fn () => StaffAlertSettings::query()->where('user_id', $this->reception->id)->sole()->approvesOnWhatsApp($this->reception)))->toBeTrue();

    // Someone who cannot decide requests cannot turn it on.
    $viewer = staffMember($this->hotel, SystemRole::ReadOnly);
    asStaffOf($this->hotel, $viewer)->put(route('hotel.my-alerts.update'), ['alert_phone' => '+2250700000099', 'alerts' => [], 'approve_by_whatsapp' => true]);
    expect(($this->in)(fn () => StaffAlertSettings::query()->where('user_id', $viewer->id)->sole()->approvesOnWhatsApp($viewer)))->toBeFalse();
});

it('sends unanswered requests to the owners after the hotel\'s time, once', function (): void {
    openStaffWindow($this, OWNER_PHONE);
    ($this->in)(fn () => StaffAlertSettings::query()->where('user_id', $this->owner->id)->update(['alerts' => []])); // the owner did not ask for request alerts
    $request = cardRequest($this);
    expect(sentToPhone($this, OWNER_PHONE))->toBe([]);

    $this->travel(14)->minutes();
    $this->artisan('booking-requests:escalate')->assertSuccessful();
    expect(sentToPhone($this, OWNER_PHONE))->toBe([]);

    $this->travel(2)->minutes();
    $this->artisan('booking-requests:escalate')->assertSuccessful();
    $this->artisan('booking-requests:escalate')->assertSuccessful();

    expect(sentToPhone($this, OWNER_PHONE))->toHaveCount(1)
        ->and(lastTextFor($this, OWNER_PHONE))->toContain('Pas de réponse depuis 16 minutes')
        ->and(array_keys(buttonsFor($this, OWNER_PHONE)))->toBe(['Accepter', 'Refuser'])
        ->and(sentToPhone($this, MANAGER_PHONE))->toHaveCount(1) // only the first alert, managers are not owners
        ->and(($this->in)(fn () => $request->fresh()->escalated_at))->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'booking_requests.escalated')->count())->toBe(1);

    // Turned off: nothing is escalated.
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['request_escalate_minutes' => 0]));
    ($this->in)(fn () => BookingRequest::query()->update(['status' => BookingRequestStatus::Withdrawn]));
    cardRequest($this);
    $this->travel(30)->minutes();
    $this->artisan('booking-requests:escalate')->assertSuccessful();
    expect(sentToPhone($this, OWNER_PHONE))->toHaveCount(1);
});

it('lets the owner set the escalation time', function (): void {
    $page = json_decode((string) json_encode(asStaffOf($this->hotel, $this->owner)->get(route('hotel.assistant'))->viewData('page')['props']['settings']), true);
    expect($page['request_escalate_minutes'])->toBe(15);
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.assistant.update'), [...$page, 'request_escalate_minutes' => 30, 'human_keywords' => ''])->assertSessionHasNoErrors();
    expect(($this->in)(fn () => HotelAssistantSettings::current()->request_escalate_minutes))->toBe(30);
});

it('submits the button template to Meta with its quick-reply buttons', function (): void {
    $template = ($this->in)(fn () => app(TemplateService::class)->createFromStarter('booking_request_decision', 'fr'));
    Http::fake(['graph.facebook.com/*' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['id' => 'tpl-1', 'status' => 'PENDING']);
    }]);

    ($this->in)(fn () => app(TemplateService::class)->submit($template));

    $components = collect($this->sent)->last()['components'];
    expect($components[1])->toBe(['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Accepter'], ['type' => 'QUICK_REPLY', 'text' => 'Refuser']]]);
});
