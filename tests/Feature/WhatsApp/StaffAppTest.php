<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Push\FakePushTransport;
use App\Domains\Push\Models\PushSubscription;
use App\Domains\Push\PushTransport;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\StaffActions\ActionToken;
use App\Domains\StaffActions\Models\StaffAction;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\SendWhatsAppIfUnopened;
use App\Domains\StaffAlerts\StaffAlert;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\StarterTemplates;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

const APP_PHONE = '2250700000040';
const FCM = 'https://fcm.googleapis.com/fcm/send/abc123';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class, SendWhatsAppIfUnopened::class]);
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));
    $starter = StarterTemplates::all()['staff_alert'];
    ($this->in)(fn () => MessageTemplate::query()->create(['starter_key' => 'staff_alert', 'name' => 'staff_alert', 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]));
    $this->whatsapp = [];
    Http::fake(['graph.facebook.com/*' => function ($request) {
        $this->whatsapp[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.S'.count($this->whatsapp)]]]);
    }]);
    $this->push = new FakePushTransport;
    app()->instance(PushTransport::class, $this->push);

    $this->owner = staffMember($this->hotel, SystemRole::Owner, ['first_name' => 'Awa']);
    ($this->in)(fn () => StaffAlertSettings::query()->create(['user_id' => $this->owner->id, 'alert_phone' => '+'.APP_PHONE, 'alerts' => ['new_request', 'handover']]));
    webhookPost($this, [inbound('wamid.G1', from: '2250700000001', name: 'Awa Koné')]);
    $this->conversation = ($this->in)(fn () => Conversation::query()->with('contact.guest')->sole());
    $this->whatsapp = [];
});

function subscribePhone(object $test, string $endpoint = FCM): void
{
    asStaffOf($test->hotel, $test->owner)->postJson(route('hotel.push.subscribe'), [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],  // gitleaks:allow public sample keys of a browser subscription
        'device' => 'Android · Chrome',
    ])->assertOk();
}

function appRequest(object $test, string $expiresIn = '+2 hours'): BookingRequest
{
    return ($test->in)(fn () => app(BookingRequestService::class)->submit(
        $test->conversation,
        new StayRequest($test->exe->id, CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-14'), adults: 2, rooms: 1),
        'Awa Koné',
    ));
}

/** Runs the queued WhatsApp backups, as the worker would after 5 minutes. */
function runBackups(object $test): void
{
    Queue::assertPushed(SendWhatsAppIfUnopened::class);
    foreach (Queue::pushed(SendWhatsAppIfUnopened::class) as $job) {
        ($test->in)(fn () => app()->call([$job, 'handle']));
    }
}

it('lets staff turn notifications on for a phone, only through known push services', function (): void {
    subscribePhone($this);

    $row = DB::table('push_subscriptions')->sole();
    expect($row->endpoint)->not->toContain('fcm.googleapis.com') // stored encrypted
        ->and(($this->in)(fn () => PushSubscription::query()->sole()->endpoint))->toBe(FCM)
        ->and(AuditLog::query()->where('event', 'push.subscribed')->count())->toBe(1);

    // An address chosen by the caller would make the server call it: refused.
    asStaffOf($this->hotel, $this->owner)->postJson(route('hotel.push.subscribe'), ['endpoint' => 'https://169.254.169.254/latest', 'keys' => ['p256dh' => 'abc', 'auth' => 'def']])->assertUnprocessable();
    asStaffOf($this->hotel, $this->owner)->postJson(route('hotel.push.subscribe'), ['endpoint' => 'http://fcm.googleapis.com/x', 'keys' => ['p256dh' => 'abc', 'auth' => 'def']])->assertUnprocessable();

    // The same phone signed in by someone else becomes theirs.
    $manager = staffMember($this->hotel, SystemRole::Manager);
    asStaffOf($this->hotel, $manager)->postJson(route('hotel.push.subscribe'), ['endpoint' => FCM, 'keys' => ['p256dh' => 'BNcR', 'auth' => 'tBHI']])->assertOk();
    expect(($this->in)(fn () => PushSubscription::query()->sole()->user_id))->toBe($manager->id);

    asStaffOf($this->hotel, $manager)->deleteJson(route('hotel.push.unsubscribe'), ['endpoint' => FCM])->assertOk();
    expect(($this->in)(fn () => PushSubscription::query()->count()))->toBe(0);
});

it('sends a test notification, and removes phones that unsubscribed or keep failing', function (): void {
    subscribePhone($this);
    asStaffOf($this->hotel, $this->owner)->postJson(route('hotel.push.test'))->assertOk()->assertJson(['sent' => 1]); // addition 3
    expect($this->push->payloads()[0]['title'])->toBe('Notification de test');

    $id = ($this->in)(fn () => PushSubscription::query()->sole()->id);
    $this->push->results[$id] = 'gone';
    asStaffOf($this->hotel, $this->owner)->postJson(route('hotel.push.test'))->assertJson(['sent' => 0]);
    expect(($this->in)(fn () => PushSubscription::query()->count()))->toBe(0);
});

it('sends a new request to the app first, with Accept on it, and WhatsApp only if it is not opened', function (): void {
    subscribePhone($this);
    $request = appRequest($this);

    $payload = $this->push->payloads()[0];
    expect($payload['title'])->toContain('First Class Lodge')
        ->and($payload['body'])->toContain('Awa Koné')->toContain('2 nuits')
        ->and(array_column($payload['actions'], 'title'))->toBe(['Accepter', 'Refuser'])
        ->and($payload['actions'][0]['action'])->toBe('act') // addition 1
        ->and(ActionToken::looksLikeOne($payload['actions'][0]['token']))->toBeTrue()
        ->and($payload['actions'][1]['action'])->toBe('open') // declining needs a reason: it opens the app
        ->and($payload['urgent'])->toBeTrue()
        ->and($payload['badge'])->toBe(1) // addition 6
        ->and($this->whatsapp)->toBe([]); // free: no Meta message yet

    // Not opened after 5 minutes: the WhatsApp alert follows (addition 4).
    runBackups($this);
    expect($this->whatsapp)->toHaveCount(1)
        ->and(($this->in)(fn () => StaffAlert::query()->where('how', 'push_unopened')->count()))->toBe(1);

    // Opened in time: no WhatsApp. A wrong signature is refused.
    $this->whatsapp = [];
    Queue::fake([ReplyToGuest::class, SendWhatsAppIfUnopened::class]);
    ($this->in)(fn () => BookingRequest::query()->update(['status' => BookingRequestStatus::Withdrawn]));
    $this->travel(11)->minutes();
    appRequest($this);
    $next = collect($this->push->payloads())->last();
    $this->postJson(route('push.opened'), ['hotel' => $this->hotel->id, 'alert' => $next['alert'], 'signature' => str_repeat('0', 32)])->assertNotFound();
    $this->postJson(route('push.opened'), ['hotel' => $this->hotel->id, 'alert' => $next['alert'], 'signature' => $next['opened']])->assertOk();
    runBackups($this);
    expect($this->whatsapp)->toBe([]);
});

it('accepts from the notification with the same rules, undo and result in the app', function (): void {
    subscribePhone($this);
    $request = appRequest($this);
    $accept = $this->push->payloads()[0]['actions'][0]['token'];

    $r = $this->postJson(route('push.act'), ['hotel' => $this->hotel->id, 'token' => $accept]);
    $replies = $r->json('replies');
    expect($replies[0]['text'])->toContain('Acceptation de la demande de Awa Koné')
        ->and($replies[0]['buttons'][0]['title'])->toBe('Annuler')
        ->and(($this->in)(fn () => StaffAction::query()->sole()->phone))->toBeNull() // the result goes to the app
        ->and($this->whatsapp)->toBe([]);

    $this->travel(91)->seconds();
    $this->artisan('staff-actions:run-due')->assertSuccessful();

    expect(($this->in)(fn () => $request->fresh()->status))->toBe(BookingRequestStatus::Approved)
        ->and(collect($this->push->payloads())->last()['body'])->toContain('Le client a été informé');

    // A second tap: who handled it. Undo from the notification after the minute: too late.
    $again = $this->postJson(route('push.act'), ['hotel' => $this->hotel->id, 'token' => $accept])->json('replies');
    expect($again[0]['text'])->toContain('Déjà acceptée par');
});

it('refuses notification taps that are not signed for a member with the app', function (): void {
    subscribePhone($this);
    $request = appRequest($this);
    $accept = $this->push->payloads()[0]['actions'][0]['token'];

    expect($this->postJson(route('push.act'), ['hotel' => $this->hotel->id, 'token' => substr($accept, 0, -2).'xx'])->json('replies'))->toBe([])
        ->and($this->postJson(route('push.act'), ['hotel' => Hotel::factory()->create()->id, 'token' => $accept])->json('replies'))->toBe([])
        ->and($this->postJson(route('push.act'), ['hotel' => 'nope', 'token' => $accept])->status())->toBe(404);

    // Logged out on that phone (no subscription left): the code no longer works.
    ($this->in)(fn () => PushSubscription::query()->delete());
    expect($this->postJson(route('push.act'), ['hotel' => $this->hotel->id, 'token' => $accept])->json('replies'))->toBe([])
        ->and(($this->in)(fn () => StaffAction::query()->count()))->toBe(0)
        ->and(AuditLog::query()->where('event', 'staff_actions.refused')->count())->toBeGreaterThanOrEqual(3);
});

it('falls back to WhatsApp at once when no phone gets the notification', function (): void {
    subscribePhone($this);
    $this->push->results[($this->in)(fn () => PushSubscription::query()->sole()->id)] = 'failed';

    appRequest($this);

    expect($this->whatsapp)->toHaveCount(1)
        ->and(($this->in)(fn () => StaffAlert::query()->where('reason', 'push_failed')->count()))->toBe(1);
});

it('keeps quiet hours, except for a request about to expire', function (): void {
    subscribePhone($this);
    ($this->in)(fn () => StaffAlertSettings::query()->update(['quiet_from' => '22:00', 'quiet_to' => '06:00']));
    $this->travelTo('2026-11-01 23:30:00');

    $request = appRequest($this);
    expect($this->push->sent)->toBe([])->and($this->whatsapp)->toBe([])
        ->and(($this->in)(fn () => StaffAlert::query()->where('reason', 'quiet_hours')->count()))->toBe(1);

    // 30 minutes left: the last call goes through quiet hours, once.
    $this->travelTo($request->expires_at->subMinutes(25));
    $this->artisan('booking-requests:escalate')->assertSuccessful();
    $this->artisan('booking-requests:escalate')->assertSuccessful();
    expect($this->push->payloads())->toHaveCount(1)
        ->and($this->push->payloads()[0]['body'])->toContain('Awa Koné')
        ->and(($this->in)(fn () => $request->fresh()->last_call_at))->not->toBeNull();

    // The test alert is never held back.
    expect(($this->in)(fn () => StaffAlertSettings::query()->sole()->inQuietHours(CarbonImmutable::parse('2026-11-02 05:59'))))->toBeTrue()
        ->and(($this->in)(fn () => StaffAlertSettings::query()->sole()->inQuietHours(CarbonImmutable::parse('2026-11-02 06:00'))))->toBeFalse();
});

it('uses WhatsApp only, as before, for staff without the app or with it turned off', function (): void {
    appRequest($this);
    expect($this->whatsapp)->toHaveCount(1)->and($this->push->sent)->toBe([]);

    subscribePhone($this);
    ($this->in)(fn () => StaffAlertSettings::query()->update(['push_enabled' => false]));
    ($this->in)(fn () => app(StaffAlerts::class)->notify(AlertKind::Handover, 'Moussa', (string) $this->conversation->id));
    expect($this->push->sent)->toBe([])->and($this->whatsapp)->toHaveCount(2);
});

it('reaches staff who have the app but no WhatsApp number', function (): void {
    ($this->in)(fn () => StaffAlertSettings::query()->update(['alert_phone' => null]));
    subscribePhone($this);

    ($this->in)(fn () => app(StaffAlerts::class)->notify(AlertKind::Handover, 'Moussa', (string) $this->conversation->id));

    expect($this->push->payloads()[0]['url'])->toBe(route('hotel.inbox.show', $this->conversation->id))
        ->and($this->whatsapp)->toBe([]);
    Queue::assertNotPushed(SendWhatsAppIfUnopened::class);
});

it('saves quiet hours and the app choice, lists the phones, and shares the badge', function (): void {
    subscribePhone($this);
    appRequest($this);

    asStaffOf($this->hotel, $this->owner)->get(route('hotel.my-alerts'))->assertInertia(fn ($page) => $page
        ->where('settings.push_enabled', true)->where('settings.quiet_on', false)
        ->where('devices.0.device', 'Android · Chrome')
        ->where('appBadge', 1)
        ->has('push'));

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.my-alerts.update'), ['alert_phone' => '+'.APP_PHONE, 'alerts' => ['new_request'], 'push_enabled' => false, 'quiet_on' => true, 'quiet_from' => '21:30', 'quiet_to' => '05:30'])->assertSessionHasNoErrors();
    $settings = ($this->in)(fn () => StaffAlertSettings::query()->sole());
    expect($settings->push_enabled)->toBeFalse()->and($settings->quiet_from)->toBe('21:30')->and($settings->quiet_to)->toBe('05:30');
});

it('serves the app manifest under the brand name, and links it from every page', function (): void {
    $this->get('/manifest.webmanifest')->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('start_url', '/hotel/today?source=app')
        ->assertJsonPath('icons.2.purpose', 'maskable');

    expect(file_exists(public_path('sw.js')))->toBeTrue()
        ->and(file_exists(public_path('icons/icon-192.png')))->toBeTrue();
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.today'))->assertSee('rel="manifest"', false);
});

it('turns the app off without VAPID keys, and makes a key pair', function (): void {
    app()->forgetInstance(PushTransport::class);
    app()->bind(PushTransport::class, App\Domains\Push\WebPushTransport::class);
    config(['webpush.public_key' => null, 'webpush.private_key' => null]);
    subscribePhone($this);
    appRequest($this);
    expect($this->whatsapp)->toHaveCount(1); // WhatsApp as before

    $this->artisan('push:keys', ['--show' => true])->expectsOutputToContain('VAPID_PUBLIC_KEY=')->assertSuccessful();
});
