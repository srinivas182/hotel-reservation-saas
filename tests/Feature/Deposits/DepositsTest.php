<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Jobs\DownloadMedia;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Deposits\Deposits;
use App\Domains\Deposits\Models\DepositAttempt;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\Deposits\Models\MerchantAccount;
use App\Domains\Folio\Folio;
use App\Domains\Folio\FolioLine;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\StaffActions\Models\StaffAction;
use App\Domains\StaffAlerts\Morning\MorningSummary;
use App\Domains\StaffAlerts\Morning\MorningSummaryText;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../WhatsApp/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';

const DEPOSIT_STAFF_PHONE = '2250700000020';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class, DownloadMedia::class]);
    config(['simulation.enabled' => true]);
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(function (): void {
        WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']);
        HotelAssistantSettings::current()->update(['deposit_required' => true, 'deposit_percent' => 30, 'deposit_hours' => 24, 'deposit_instructions' => ['fr' => 'Wave 07 00 00 00 00']]);
        MerchantAccount::query()->create(['driver' => 'simulation', 'mode' => 'test', 'credentials' => [], 'active' => true]);
    });
    $this->sent = [];
    Http::fake(['graph.facebook.com/*' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(6))]]]);
    }]);

    $this->owner = staffMember($this->hotel, SystemRole::Owner, ['first_name' => 'Awa', 'last_name' => 'Owner']);
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
    ($this->in)(fn () => StaffAlertSettings::query()->create(['user_id' => $this->owner->id, 'alert_phone' => '+'.DEPOSIT_STAFF_PHONE, 'alerts' => ['new_request', 'deposit_paid', 'deposit_proof', 'deposit_refund']])->forceFill(['whatsapp_inbound_at' => now()])->save());

    webhookPost($this, [inbound('wamid.G1', from: '2250700000001', name: 'Awa Koné')]);
    $this->conversation = ($this->in)(fn () => Conversation::query()->with('contact.guest')->sole());
    $this->sent = [];
});

/** A WhatsApp request accepted by reception: a tentative booking with its deposit link sent. */
function depositBooking(object $test): Booking
{
    $request = ($test->in)(fn (): BookingRequest => app(BookingRequestService::class)->submit(
        $test->conversation,
        new StayRequest($test->exe->id, CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-14'), adults: 2),
        'Awa Koné',
    ));
    asStaffOf($test->hotel, $test->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false])->assertRedirect();

    return ($test->in)(fn () => Booking::query()->with('guest')->sole());
}

function depositLink(object $test): DepositLink
{
    return ($test->in)(fn () => DepositLink::query()->with('booking')->sole());
}

/** The token of the link, as the guest received it. */
function depositToken(object $test): string
{
    return depositLink($test)->token;
}

function depositGuestTexts(object $test): array
{
    return array_values(array_map(
        fn (array $m): string => (string) ($m['text']['body'] ?? json_encode($m['template'] ?? [])),
        array_filter($test->sent, fn (array $m): bool => ($m['to'] ?? null) === '2250700000001'),
    ));
}

function depositStaffTexts(object $test): array
{
    return array_values(array_map(
        fn (array $m): string => (string) ($m['text']['body'] ?? $m['interactive']['body']['text'] ?? json_encode($m['template'] ?? [])),
        array_filter($test->sent, fn (array $m): bool => ($m['to'] ?? null) === DEPOSIT_STAFF_PHONE),
    ));
}

/** The guest pays through the simulated provider. */
function depositPayByLink(object $test, string $choice = 'deposit', string $method = 'wave', string $outcome = 'paid'): void
{
    $token = depositToken($test);
    $checkout = $test->post(route('deposits.pay', $token), ['choice' => $choice])->assertRedirect()->headers->get('Location');
    $reference = basename((string) parse_url((string) $checkout, PHP_URL_PATH));
    $test->post(route('deposits.simulate.pay', $reference), ['outcome' => $outcome, 'method' => $method])->assertRedirect(route('deposits.back', $token));
    $test->get(route('deposits.back', $token))->assertRedirect(route('deposits.show', $token));
}

it('sends a payment link with the deposit message, held until the deadline', function (): void {
    $booking = depositBooking($this);
    $link = depositLink($this);

    expect($booking->status)->toBe(BookingStatus::Tentative)
        ->and($link->status)->toBe('open')
        ->and($link->deposit_minor)->toBe(Deposits::payable((int) ceil($booking->total_minor * 0.3), 'XOF'))
        ->and($link->expires_at?->toIso8601String())->toBe($booking->tentative_until?->toIso8601String())
        ->and($link->sent_at)->not->toBeNull()
        ->and(depositGuestTexts($this)[0])->toContain(route('deposits.show', $link->token))
        ->and(depositGuestTexts($this)[0])->toContain('Wave 07 00 00 00 00');

    $page = $this->get(route('deposits.show', $link->token))->assertOk();
    $page->assertSee($booking->reference)->assertSee('Payer par mobile money', false)->assertSee('bloquées', false)->assertSee('2 jours', false);

    // Only the hash is stored; a wrong token is a 404.
    expect(DepositLink::query()->withoutGlobalScopes()->where('token_hash', $link->token)->exists())->toBeFalse();
    $this->get(route('deposits.show', str_repeat('x', 40)))->assertNotFound();
});

it('confirms the booking, records the payment once and sends the receipt when the guest pays', function (): void {
    $booking = depositBooking($this);
    $this->sent = [];

    depositPayByLink($this, method: 'orange_money');

    $link = depositLink($this);
    $booking->refresh();
    $line = ($this->in)(fn () => FolioLine::query()->where('type', 'payment')->sole());

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($link->status)->toBe('paid')
        ->and($link->method)->toBe('orange_money')
        ->and($link->paid_via)->toBe('online')
        ->and($link->receipt_number)->toStartWith('R261101-')
        ->and($line->amount_minor)->toBe($link->deposit_minor)
        ->and($line->method)->toBe('orange_money')
        ->and($line->source)->toBe('deposit')
        ->and($line->reference)->toBe($link->receipt_number)
        ->and(implode("\n", depositGuestTexts($this)))->toContain('Merci Awa')->toContain($link->receipt_number)->toContain('Reste à payer')
        ->and(implode("\n", depositStaffTexts($this)))->toContain('Orange Money');

    // A second notice or return changes nothing.
    $attempt = ($this->in)(fn () => DepositAttempt::query()->sole());
    ($this->in)(fn () => app(Deposits::class)->settle($attempt->refresh()));
    $this->get(route('deposits.back', $link->token));
    ($this->in)(fn () => app(Deposits::class)->markPaid($link, $link->deposit_minor, 'wave', 'online', null, null));

    expect(($this->in)(fn () => FolioLine::query()->where('type', 'payment')->count()))->toBe(1);
    $this->get(route('deposits.show', $link->token))->assertOk()->assertSee('Paiement reçu', false)->assertSee($link->receipt_number);
});

it('lets the guest pay the whole stay instead', function (): void {
    $booking = depositBooking($this);
    $full = ($this->in)(fn () => app(Folio::class)->balance($booking));

    depositPayByLink($this, choice: 'full');

    expect(depositLink($this)->paid_minor)->toBe(Deposits::payable($full, 'XOF'))
        ->and(($this->in)(fn () => app(Folio::class)->balance($booking->refresh())))->toBeLessThanOrEqual(0);
});

it('offers the whole stay at the pay-now price, and applies the discount once it is paid', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['prepay_discount_percent' => 10]));
    $booking = depositBooking($this);
    $total = $booking->total_minor;
    $prepay = Deposits::payable((int) ceil($total * 0.9), 'XOF');

    expect(implode("\n", depositGuestTexts($this)))->toContain('économisez 10 %');

    depositPayByLink($this, choice: 'full');

    $booking->refresh();
    expect(depositLink($this)->paid_minor)->toBe($prepay)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->prepaid_at)->not->toBeNull()
        ->and($booking->prepay_discount_minor)->toBe($total - $prepay)
        ->and($booking->total_minor)->toBe($prepay)
        ->and(($this->in)(fn () => app(Folio::class)->balance($booking)))->toBe(0);
});

it('gives no pay-now discount on a deposit, only on the whole stay', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['prepay_discount_percent' => 10]));
    $booking = depositBooking($this);
    $total = $booking->total_minor;

    depositPayByLink($this);

    $booking->refresh();
    expect($booking->prepaid_at)->toBeNull()
        ->and($booking->total_minor)->toBe($total)
        ->and(($this->in)(fn () => app(Deposits::class)->prepayTotal($booking)))->toBeNull();
});

it('confirms pay-at-the-hotel bookings with the pay-now offer and the time the room is kept', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['deposit_required' => false, 'prepay_discount_percent' => 10, 'pay_at_hotel_hold_until' => '18:00']));
    $booking = depositBooking($this);
    $prepay = Deposits::payable((int) ceil($booking->total_minor * 0.9), 'XOF');

    $text = implode("\n", depositGuestTexts($this));
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($text)->toContain($booking->reference)
        ->and($text)->toContain('économisez 10 %')
        ->and($text)->toContain('gardée jusqu’à 18:00')
        ->and(depositLink($this)->purpose)->toBe('balance')
        ->and(depositLink($this)->deposit_minor)->toBe($prepay);

    depositPayByLink($this);

    expect($booking->refresh()->prepaid_at)->not->toBeNull()
        ->and($booking->total_minor)->toBe($prepay);
});

it('says only the hold time when there is no pay-now offer', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['deposit_required' => false, 'pay_at_hotel_hold_until' => '17:00']));
    depositBooking($this);

    expect(implode("\n", depositGuestTexts($this)))->toContain('17:00')->not->toContain('économisez');
});

it('does nothing when the provider refuses the payment', function (): void {
    $booking = depositBooking($this);

    depositPayByLink($this, outcome: 'failed');

    expect(depositLink($this)->status)->toBe('open')
        ->and($booking->refresh()->status)->toBe(BookingStatus::Tentative)
        ->and(($this->in)(fn () => DepositAttempt::query()->sole()->status))->toBe('failed');
});

it('settles CinetPay notifications only after checking with CinetPay, for the right account', function (): void {
    $booking = depositBooking($this);
    $account = ($this->in)(function (): MerchantAccount {
        $account = MerchantAccount::query()->sole();
        $account->update(['driver' => 'cinetpay', 'credentials' => ['apikey' => 'key-123', 'site_id' => '445566', 'secret_key' => 's']]); // gitleaks:allow

        return $account;
    });
    $link = depositLink($this);
    $checked = [];
    Http::fake([
        'api-checkout.cinetpay.com/v2/payment' => Http::response(['code' => '201', 'data' => ['payment_url' => 'https://checkout.cinetpay.com/x', 'payment_token' => 'tok']]),
        'api-checkout.cinetpay.com/v2/payment/check' => function ($request) use (&$checked, $link) {
            $checked[] = $request->data()['transaction_id'];

            return Http::response(['code' => '00', 'data' => ['status' => 'ACCEPTED', 'amount' => (string) $link->deposit_minor, 'currency' => 'XOF', 'payment_method' => 'WAVECI']]);
        },
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
    ]);

    $this->post(route('deposits.pay', $link->token), ['choice' => 'deposit'])->assertRedirect('https://checkout.cinetpay.com/x');
    $reference = ($this->in)(fn () => DepositAttempt::query()->sole()->reference);

    // Wrong site id: refused, nothing checked.
    $this->post(route('webhooks.deposits', $account->id), ['cpm_trans_id' => $reference, 'cpm_site_id' => '999'])->assertStatus(401);
    expect($checked)->toBe([]);

    $this->post(route('webhooks.deposits', $account->id), ['cpm_trans_id' => $reference, 'cpm_site_id' => '445566'])->assertOk();

    expect($checked)->toBe([$reference])
        ->and(depositLink($this)->method)->toBe('wave')
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('lets a hotel take deposits by card with its own Stripe account', function (): void {
    $booking = depositBooking($this);
    $account = ($this->in)(function (): MerchantAccount {
        $account = MerchantAccount::query()->sole();
        $account->update(['driver' => 'stripe', 'mode' => 'test', 'credentials' => ['secret_key' => 'sk_test_hotel', 'webhook_secret' => 'whsec_hotel']]); // gitleaks:allow

        return $account;
    });
    $link = depositLink($this);
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/*' => fn () => Http::response([
            'id' => 'cs_dep', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => $link->deposit_minor, 'currency' => 'xof', 'payment_intent' => 'pi_dep',
            'client_reference_id' => DB::table('deposit_attempts')->value('reference'),
        ]),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_dep', 'url' => 'https://checkout.stripe.com/c/pay/cs_dep']),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
    ]);

    $this->post(route('deposits.pay', $link->token), ['choice' => 'deposit'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_dep');
    // The guest gave no email on WhatsApp: Stripe asks for it, never the platform's address.
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/checkout/sessions') && ! array_key_exists('customer_email', $request->data()));
    $reference = ($this->in)(fn () => DepositAttempt::query()->sole()->reference);
    $body = (string) json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_dep', 'client_reference_id' => $reference]]]);
    $sign = fn (string $secret): string => 't='.time().',v1='.hash_hmac('sha256', time().'.'.$body, $secret);

    $this->call('POST', route('webhooks.deposits', $account->id), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sign('whsec_other'), 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
    expect($booking->refresh()->status)->not->toBe(BookingStatus::Confirmed);

    // 0.35.7: the hotel's address, shown before the first save, reaches the same account.
    $this->call('POST', route('webhooks.deposits.hotel', $this->hotel->id), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sign('whsec_hotel'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect(depositLink($this)->method)->toBe('card')
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('rejects a payment whose amount differs from what was asked', function (): void {
    depositBooking($this);
    ($this->in)(fn () => MerchantAccount::query()->sole()->update(['driver' => 'cinetpay', 'credentials' => ['apikey' => 'k', 'site_id' => '1', 'secret_key' => 's']]));
    Http::fake([
        'api-checkout.cinetpay.com/v2/payment' => Http::response(['code' => '201', 'data' => ['payment_url' => 'https://checkout.cinetpay.com/x', 'payment_token' => 'tok']]),
        'api-checkout.cinetpay.com/v2/payment/check' => Http::response(['code' => '00', 'data' => ['status' => 'ACCEPTED', 'amount' => '100', 'currency' => 'XOF']]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
    ]);

    $this->post(route('deposits.pay', depositToken($this)), ['choice' => 'deposit']);
    $this->get(route('deposits.back', depositToken($this)));

    expect(depositLink($this)->status)->toBe('open')
        ->and(($this->in)(fn () => DepositAttempt::query()->sole()->status))->toBe('failed')
        ->and(($this->in)(fn () => AuditLog::query()->where('event', 'deposits.mismatch')->exists()))->toBeTrue();
});

it('reminds the guest once, two hours before the rooms are released, then closes the link', function (): void {
    $booking = depositBooking($this);
    $this->sent = [];

    $this->travelTo($booking->tentative_until->subMinutes(150));
    $this->artisan('deposits:remind')->assertSuccessful();
    expect(depositGuestTexts($this))->toBe([]);

    $this->travelTo($booking->tentative_until->subMinutes(90));
    // The guest wrote recently, so the reminder is a free message.
    webhookPost($this, [inbound('wamid.G2', from: '2250700000001', name: 'Awa Koné')]);
    $this->artisan('deposits:remind')->assertSuccessful();
    $this->artisan('deposits:remind')->assertSuccessful();

    $reminders = array_values(array_filter(depositGuestTexts($this), fn (string $t): bool => str_contains($t, 'rappel')));
    expect($reminders)->toHaveCount(1)
        ->and($reminders[0])->toContain(route('deposits.show', depositToken($this)));

    $this->travelTo($booking->tentative_until->addMinute());
    $this->artisan('bookings:expire-tentative')->assertSuccessful();

    expect(depositLink($this)->status)->toBe('expired');
    $this->get(route('deposits.show', depositToken($this)))->assertOk()->assertSee('plus valable', false);
    $this->post(route('deposits.pay', depositToken($this)), ['choice' => 'deposit'])->assertRedirect(route('deposits.show', depositToken($this)));
    expect(($this->in)(fn () => DepositAttempt::query()->count()))->toBe(0);
});

it('asks staff to check a screenshot and records the deposit when they tap Received', function (): void {
    $booking = depositBooking($this);
    $this->sent = [];

    webhookPost($this, [inbound('wamid.PROOF', from: '2250700000001', message: ['type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg']], name: 'Awa Koné')]);

    $card = collect($this->sent)->first(fn (array $m): bool => ($m['to'] ?? null) === DEPOSIT_STAFF_PHONE && ($m['type'] ?? null) === 'interactive');
    expect($card)->not->toBeNull()
        ->and($card['interactive']['body']['text'])->toContain('Awa Koné')->toContain($booking->reference);
    $buttons = array_column(array_column($card['interactive']['action']['buttons'], 'reply'), 'id', 'title');

    // A second screenshot right after does not alert again.
    webhookPost($this, [inbound('wamid.PROOF2', from: '2250700000001', message: ['type' => 'image', 'image' => ['id' => 'media-2', 'mime_type' => 'image/jpeg']], name: 'Awa Koné')]);
    expect(collect($this->sent)->filter(fn (array $m): bool => ($m['to'] ?? null) === DEPOSIT_STAFF_PHONE && ($m['type'] ?? null) === 'interactive')->count())->toBe(1);

    webhookPost($this, [inbound('wamid.TAP1', from: DEPOSIT_STAFF_PHONE, message: ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $buttons['Reçu'], 'title' => 'Reçu']]], name: 'Staff')]);
    expect(depositLink($this)->status)->toBe('open');

    $this->travel(91)->seconds();
    $this->artisan('staff-actions:run-due')->assertSuccessful();

    $link = depositLink($this);
    expect($link->status)->toBe('paid')
        ->and($link->paid_via)->toBe('manual')
        ->and($link->confirmed_by)->toBe($this->owner->id)
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(($this->in)(fn () => StaffAction::query()->sole()->status))->toBe('done');
});

it('records a card or mobile-money payment from the provider instead of asking staff when the guest sends its screenshot', function (): void {
    $booking = depositBooking($this);
    $token = depositToken($this);

    // The guest pays but closes the page before coming back to the hotel.
    $checkout = $this->post(route('deposits.pay', $token), ['choice' => 'deposit'])->assertRedirect()->headers->get('Location');
    $reference = basename((string) parse_url((string) $checkout, PHP_URL_PATH));
    $this->post(route('deposits.simulate.pay', $reference), ['outcome' => 'paid', 'method' => 'wave']);
    expect(depositLink($this)->status)->toBe('open');

    $this->sent = [];
    webhookPost($this, [inbound('wamid.PROOF9', from: '2250700000001', message: ['type' => 'image', 'image' => ['id' => 'media-9', 'mime_type' => 'image/jpeg']], name: 'Awa Koné')]);

    expect(depositLink($this)->status)->toBe('paid')
        ->and(depositLink($this)->paid_via)->toBe('online')
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(collect($this->sent)->first(fn (array $m): bool => ($m['to'] ?? null) === DEPOSIT_STAFF_PHONE && ($m['type'] ?? null) === 'interactive'))->toBeNull();
});

it('lets staff send the link and confirm a transfer from the booking page', function (): void {
    $booking = depositBooking($this);
    $this->sent = [];

    asStaffOf($this->hotel, $this->reception)->get(route('hotel.bookings.show', $booking))->assertOk()
        ->assertInertia(fn ($page) => $page->where('deposit.link.status', 'open')->where('deposit.can_confirm', true)->where('deposit.online', true));

    webhookPost($this, [inbound('wamid.G3', from: '2250700000001', name: 'Awa Koné')]);
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.deposit.send', $booking))->assertRedirect();
    expect(implode("\n", depositGuestTexts($this)))->toContain(route('deposits.show', depositToken($this)));

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.deposit.confirm', $booking), ['method' => 'cash'])->assertRedirect();
    expect(depositLink($this)->method)->toBe('cash')
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed);

    // Housekeeping cannot record payments.
    $housekeeper = staffMember($this->hotel, SystemRole::ReadOnly);
    asStaffOf($this->hotel, $housekeeper)->post(route('hotel.bookings.deposit.confirm', $booking), ['method' => 'cash'])->assertForbidden();
});

it('works out refunds under the hotel policy when a paid booking is cancelled', function (): void {
    $booking = depositBooking($this);
    depositPayByLink($this);

    // Cancelled 11 days before arrival: refundable (policy: 2 days).
    ($this->in)(fn () => app(BookingLifecycle::class)->cancel($booking->refresh(), 'Plans changed', $this->owner));
    $link = depositLink($this);
    expect($link->refund_status)->toBe('due')->and($link->refund_due_minor)->toBe($link->paid_minor);

    asStaffOf($this->hotel, $this->owner)->get(route('hotel.deposits'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('refunds', 1)->where('refunds.0.reference', $booking->reference));

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.deposits.refunded', $link), ['note' => 'Renvoyé par Wave'])->assertRedirect();
    expect(depositLink($this)->refund_status)->toBe('done')
        ->and(($this->in)(fn () => FolioLine::query()->where('corrects_line_id', $link->folio_line_id)->exists()))->toBeTrue();
});

it('keeps the deposit when the cancellation comes too late', function (): void {
    $booking = depositBooking($this);
    depositPayByLink($this);

    $this->travelTo('2026-11-11 09:00:00');
    ($this->in)(fn () => app(BookingLifecycle::class)->cancel($booking->refresh(), 'No longer coming', $this->owner));

    expect(depositLink($this)->refund_status)->toBe('kept');
});

it('records a payment that arrives after the rooms were released, and asks staff to rebook or refund', function (): void {
    $booking = depositBooking($this);
    $token = depositToken($this);
    $checkout = $this->post(route('deposits.pay', $token), ['choice' => 'deposit'])->headers->get('Location');
    $reference = basename((string) parse_url((string) $checkout, PHP_URL_PATH));

    $this->travelTo($booking->tentative_until->addMinute());
    $this->artisan('bookings:expire-tentative')->assertSuccessful();
    $this->sent = [];
    ($this->in)(fn () => StaffAlertSettings::query()->update(['whatsapp_inbound_at' => now()]));

    $this->post(route('deposits.simulate.pay', $reference), ['outcome' => 'paid', 'method' => 'wave']);
    $this->get(route('deposits.back', $token));

    $link = depositLink($this);
    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($link->status)->toBe('paid')
        ->and($link->refund_status)->toBe('due')
        ->and(implode("\n", depositStaffTexts($this)))->toContain('rembourser');
});

it('reports yesterday\'s deposits in the morning summary and exports the month', function (): void {
    depositBooking($this);
    depositPayByLink($this, method: 'wave');
    $this->travelTo('2026-11-02 08:00:00');

    $text = ($this->in)(fn () => MorningSummaryText::message(app(MorningSummary::class)->build(), 'Awa', 'fr', true));
    expect($text)->toContain('Acomptes reçus hier : Wave 1');

    $csv = asStaffOf($this->hotel, $this->owner)->get(route('hotel.deposits.export', ['month' => '2026-11']))->assertOk()->streamedContent();
    expect($csv)->toContain(depositLink($this)->receipt_number)->toContain('Wave')->toContain('Awa Koné');
});

it('keeps the merchant keys secret and checks them with the provider', function (): void {
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.deposits.account'), [
        'driver' => 'cinetpay', 'mode' => 'live', 'active' => true,
        'credentials' => ['apikey' => 'live-key-987654', 'site_id' => '112233', 'secret_key' => 'secret-abcd'], // gitleaks:allow
    ])->assertRedirect();

    asStaffOf($this->hotel, $this->owner)->get(route('hotel.deposits'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('accounts.cinetpay.masked.apikey', '••••••7654')->where('accounts.cinetpay.driver', 'cinetpay'))
        ->assertDontSee('live-key-987654');

    // Saving again with empty keys keeps them.
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.deposits.account'), ['driver' => 'cinetpay', 'mode' => 'live', 'active' => true, 'credentials' => ['apikey' => '']])->assertRedirect();
    expect(($this->in)(fn () => MerchantAccount::forDriver('cinetpay')?->credential('apikey')))->toBe('live-key-987654'); // gitleaks:allow
    // 0.36: one account per provider; the simulation account is untouched.
    expect(($this->in)(fn () => MerchantAccount::query()->count()))->toBe(2);

    Http::fake(['api-checkout.cinetpay.com/*' => Http::response(['code' => '609', 'message' => 'AUTH_NOT_FOUND'])]);
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.deposits.account.check'), ['driver' => 'cinetpay'])->assertRedirect();
    expect(($this->in)(fn () => MerchantAccount::forDriver('cinetpay')?->last_error))->toBe('AUTH_NOT_FOUND');

    // Reception neither sees nor changes the account.
    asStaffOf($this->hotel, $this->reception)->get(route('hotel.deposits'))->assertOk()->assertInertia(fn ($page) => $page->where('accounts', [])->where('bank', null));
    asStaffOf($this->hotel, $this->reception)->put(route('hotel.deposits.account'), ['driver' => 'cinetpay', 'mode' => 'live', 'active' => false])->assertForbidden();
});

it('never lets another hotel touch a deposit', function (): void {
    depositBooking($this);
    depositPayByLink($this);
    ($this->in)(fn () => app(BookingLifecycle::class)->cancel(Booking::query()->sole(), 'x', $this->owner));
    $link = depositLink($this);

    $other = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $otherOwner = staffMember($other, SystemRole::Owner);

    asStaffOf($other, $otherOwner)->post(route('hotel.deposits.refunded', $link), ['note' => 'x'])->assertNotFound();
    asStaffOf($other, $otherOwner)->get(route('hotel.deposits'))->assertOk()->assertInertia(fn ($page) => $page->has('refunds', 0)->has('paid', 0));
});

it('shows the hotel its notification address before any payment account is saved', function (): void {
    ($this->in)(fn () => MerchantAccount::query()->delete());

    asStaffOf($this->hotel, $this->owner)->get(route('hotel.deposits'))
        ->assertInertia(fn ($page) => $page->where('accounts', [])
            ->where('notifyUrl', route('webhooks.deposits.hotel', $this->hotel->id)));

    $this->postJson(route('webhooks.deposits.hotel', $this->hotel->id), [])->assertNotFound();
    $this->postJson(route('webhooks.deposits.hotel', '01ZZZZZZZZZZZZZZZZZZZZZZZZ'), [])->assertNotFound();
});

/*
| 0.36: several payment methods at once, and bank transfer.
*/

function depositBank(object $test, int $prepay = 10): void
{
    ($test->in)(fn () => HotelAssistantSettings::current()->forceFill([
        'deposit_allow_full' => true,
        'prepay_discount_percent' => $prepay,
        'bank_transfer' => ['enabled' => true, 'bank_name' => 'SGBCI', 'account_holder' => 'CNH Test Hotel SARL', 'account_number' => 'CI008 01234 000123456789 12', 'iban' => '', 'swift' => 'SGCICIAB', 'branch' => 'Plateau', 'note' => ''],
    ])->save());
}

it('offers every payment method the hotel turns on, and bank transfer', function (): void {
    ($this->in)(fn () => MerchantAccount::query()->create(['driver' => 'stripe', 'mode' => 'test', 'credentials' => ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x'], 'active' => true])); // gitleaks:allow
    depositBank($this);
    depositBooking($this);

    $this->get(route('deposits.show', depositToken($this)))->assertOk()
        ->assertSee('value="simulation"', false)
        ->assertSee('value="stripe"', false)
        ->assertSee('value="bank_transfer"', false);

    // The bank details stay hidden until the guest chooses a transfer.
    $this->get(route('deposits.show', depositToken($this)))->assertDontSee('CI008 01234');
});

it('takes a bank transfer at the pay-now price once staff see the money', function (): void {
    Storage::fake('local');
    depositBank($this, prepay: 10);
    $booking = depositBooking($this);
    $token = depositToken($this);
    $expected = ($this->in)(fn () => app(Deposits::class)->fullAmount(depositLink($this)));

    $this->post(route('deposits.pay', $token), ['choice' => 'full', 'method' => 'bank_transfer'])->assertRedirect(route('deposits.show', $token));
    $attempt = ($this->in)(fn () => DepositAttempt::query()->sole());

    expect($attempt->method)->toBe('bank_transfer')
        ->and($attempt->merchant_account_id)->toBeNull()
        ->and($attempt->amount_minor)->toBe($expected)
        ->and($attempt->reference)->toStartWith('VIR-'.$booking->reference)
        // 24-hour deposit hold < 48 hours: the transfer must arrive before the rooms are released.
        ->and($attempt->expires_at?->toIso8601String())->toBe(depositLink($this)->expires_at?->toIso8601String());

    $this->get(route('deposits.show', $token))->assertOk()->assertSee($attempt->reference)->assertSee('CNH Test Hotel SARL')->assertSee('CI008 01234 000123456789 12');

    $this->sent = [];
    $this->post(route('deposits.proof', $token), ['receipt' => UploadedFile::fake()->image('recu.jpg')])->assertRedirect(route('deposits.show', $token));
    $attempt->refresh();
    expect($attempt->proof_at)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $attempt->proof_path))->toBeTrue()
        ->and(implode(' ', depositStaffTexts($this)))->toContain($attempt->reference);

    // Nothing is confirmed by the receipt alone.
    expect($booking->refresh()->status)->toBe(BookingStatus::Tentative);

    asStaffOf($this->hotel, $this->owner)->get(route('hotel.deposits'))->assertInertia(fn ($page) => $page->has('transfers', 1)->where('transfers.0.reference', $attempt->reference)
        // 0.37.2: the receipt to check shows as a count on Deposits in the menu.
        ->where('actionCounts.deposits', 1));
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.deposits.transfers.proof', $attempt->id))->assertOk();

    $this->sent = [];
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.deposits.transfers.approve', $attempt->id))->assertRedirect();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->prepay_discount_minor)->toBeGreaterThan(0)
        ->and(depositLink($this)->method)->toBe('bank_transfer')
        ->and(depositLink($this)->provider_reference)->toBe($attempt->reference)
        ->and($attempt->refresh()->status)->toBe('paid')
        ->and(implode(' ', depositGuestTexts($this)))->toContain(depositLink($this)->receipt_number);

    // Approving twice does nothing.
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.deposits.transfers.approve', $attempt->id))->assertSessionHasErrors('transfer');
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.deposits'))->assertInertia(fn ($page) => $page->where('actionCounts.deposits', 0));

    // Another hotel never sees the receipt.
    $other = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    asStaffOf($other, staffMember($other, SystemRole::Owner))->get(route('hotel.deposits.transfers.proof', $attempt->id))->assertNotFound();
});

it('lets the guest pay no other way while the hotel checks a transfer receipt, and again once it is rejected', function (): void {
    Storage::fake('local');
    ($this->in)(fn () => MerchantAccount::query()->create(['driver' => 'stripe', 'mode' => 'test', 'credentials' => ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x'], 'active' => true])); // gitleaks:allow
    depositBank($this);
    depositBooking($this);
    $token = depositToken($this);

    $this->post(route('deposits.pay', $token), ['choice' => 'full', 'method' => 'bank_transfer']);
    // Before the receipt, the guest may still change their mind.
    $this->get(route('deposits.show', $token))->assertOk()->assertSee('value="stripe"', false);

    $this->post(route('deposits.proof', $token), ['receipt' => UploadedFile::fake()->image('recu.jpg')]);
    $attempt = ($this->in)(fn () => DepositAttempt::query()->sole());

    $this->get(route('deposits.show', $token))->assertOk()
        ->assertDontSee('value="stripe"', false)
        ->assertDontSee('value="simulation"', false)
        ->assertSee($attempt->reference);
    $this->post(route('deposits.pay', $token), ['choice' => 'full', 'method' => 'simulation'])
        ->assertRedirect(route('deposits.show', $token))
        ->assertSessionHas('deposit_error');
    expect(($this->in)(fn () => DepositAttempt::query()->count()))->toBe(1);

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.deposits.transfers.reject', $attempt->id), ['reason' => 'not found'])->assertRedirect();

    $this->get(route('deposits.show', $token))->assertOk()->assertSee('value="stripe"', false);
});

it('ends a transfer still waiting for its receipt when the guest pays another way', function (): void {
    depositBank($this);
    depositBooking($this);
    $token = depositToken($this);

    $this->post(route('deposits.pay', $token), ['choice' => 'deposit', 'method' => 'bank_transfer']);
    Illuminate\Support\Facades\Exceptions::fake();
    depositPayByLink($this);
    Illuminate\Support\Facades\Exceptions::assertNothingReported();

    expect(depositLink($this)->status)->toBe('paid')
        ->and(($this->in)(fn () => DepositAttempt::query()->where('method', 'bank_transfer')->sole()->status))->toBe('failed');
});

it('tells the guest when a transfer is not found or arrives too late', function (): void {
    depositBank($this);
    depositBooking($this);
    $token = depositToken($this);

    $this->post(route('deposits.pay', $token), ['choice' => 'deposit', 'method' => 'bank_transfer']);
    $first = ($this->in)(fn () => DepositAttempt::query()->sole());
    $this->sent = [];
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.deposits.transfers.reject', $first->id), ['reason' => 'aucun virement avec cette référence'])->assertRedirect();

    expect($first->refresh()->status)->toBe('rejected')
        ->and(implode(' ', depositGuestTexts($this)))->toContain('aucun virement avec cette référence')
        ->and(depositLink($this)->status)->toBe('open');

    // A new transfer, no receipt, deadline passes: it ends and the guest is told.
    $this->post(route('deposits.pay', $token), ['choice' => 'deposit', 'method' => 'bank_transfer']);
    $second = ($this->in)(fn () => DepositAttempt::query()->where('status', 'pending')->sole());
    $this->sent = [];
    $this->travelTo($second->expires_at->addMinute());
    $this->artisan('deposits:remind')->assertSuccessful();

    // The guest wrote more than 24 hours ago: no free text is possible, so staff are asked to tell them.
    expect($second->refresh()->status)->toBe('expired')
        ->and($this->conversation->refresh()->handover_reason)->toBe('deposit_not_told:transfer_expired');
});

it('offers no transfer when it could not arrive before the deadline', function (): void {
    depositBank($this);
    $request = ($this->in)(fn (): BookingRequest => app(BookingRequestService::class)->submit(
        $this->conversation,
        new StayRequest($this->exe->id, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-03'), adults: 1),
        'Awa Koné',
    ));
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false]);
    $token = depositToken($this);

    $this->get(route('deposits.show', $token))->assertOk()->assertDontSee('value="bank_transfer"', false);
    $this->post(route('deposits.pay', $token), ['choice' => 'deposit', 'method' => 'bank_transfer'])->assertRedirect(route('deposits.show', $token));
    expect(($this->in)(fn () => DepositAttempt::query()->count()))->toBe(0);
});

it('routes a notification on the hotel address to the provider that signed it', function (): void {
    ($this->in)(function (): void {
        MerchantAccount::query()->delete();
        MerchantAccount::query()->create(['driver' => 'cinetpay', 'mode' => 'test', 'credentials' => ['apikey' => 'k', 'site_id' => '445566', 'secret_key' => 's'], 'active' => true]); // gitleaks:allow
        MerchantAccount::query()->create(['driver' => 'stripe', 'mode' => 'test', 'credentials' => ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x'], 'active' => true]); // gitleaks:allow
    });

    $this->post(route('webhooks.deposits.hotel', $this->hotel->id), ['cpm_trans_id' => 'DEPX', 'cpm_site_id' => '999'])->assertStatus(401);
    $this->post(route('webhooks.deposits.hotel', $this->hotel->id), ['cpm_trans_id' => 'DEPX', 'cpm_site_id' => '445566'])->assertOk();
});

it('lets the hotel save its bank details only when complete, encrypted', function (): void {
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.deposits.bank'), ['enabled' => true, 'account_holder' => 'CNH'])->assertSessionHasErrors('account_number');

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.deposits.bank'), ['enabled' => true, 'account_holder' => 'CNH Test Hotel SARL', 'account_number' => 'CI008 0001', 'bank_name' => 'SGBCI'])->assertRedirect();

    expect(($this->in)(fn () => HotelAssistantSettings::current()->takesBankTransfer()))->toBeTrue()
        ->and((string) DB::table('hotel_assistant_settings')->value('bank_transfer'))->not->toContain('CI008');

    asStaffOf($this->hotel, $this->reception)->put(route('hotel.deposits.bank'), ['enabled' => false])->assertForbidden();
});

it('refunds an online payment from the app once, records it and tells the guest', function (): void {
    $booking = depositBooking($this);
    depositPayByLink($this);
    ($this->in)(fn () => app(BookingLifecycle::class)->cancel($booking->refresh(), 'Plans changed', $this->owner));
    $link = depositLink($this);

    // The red count on Deposits includes refunds to make.
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.deposits'))
        ->assertInertia(fn ($page) => $page->where('refunds.0.online_refund', true)->where('actionCounts.deposits', 1));

    $this->sent = [];
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.deposits.refund-online', $link))->assertSessionHasNoErrors()->assertRedirect();

    $link = depositLink($this);
    expect($link->refund_status)->toBe('done')
        ->and($link->refund_note)->toContain('sim-refund-')
        ->and(($this->in)(fn () => FolioLine::query()->where('corrects_line_id', $link->folio_line_id)->exists()))->toBeTrue()
        ->and(implode("\n", depositGuestTexts($this)))->toContain('rembours');

    // A second click refunds nothing more.
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.deposits.refund-online', $link))->assertSessionHasErrors('refund');
});

it('leaves cash and transfer refunds manual', function (): void {
    $booking = depositBooking($this);
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.deposit.confirm', $booking), ['method' => 'cash'])->assertRedirect();
    ($this->in)(fn () => app(BookingLifecycle::class)->cancel($booking->refresh(), 'Plans changed', $this->owner));

    asStaffOf($this->hotel, $this->owner)->get(route('hotel.deposits'))->assertInertia(fn ($page) => $page->where('refunds.0.online_refund', false));
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.deposits.refund-online', depositLink($this)))->assertSessionHasErrors('refund');
});

it('asks Stripe for a refund of the payment intent, once', function (): void {
    Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_123', 'status' => 'succeeded'])]);
    $account = new MerchantAccount(['driver' => 'stripe', 'mode' => 'test']);
    $account->credentials = ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x']; // gitleaks:allow
    $stripe = new App\Domains\Billing\Payments\Drivers\Stripe($account);

    expect($stripe->canRefund('stripe:cs_abc'))->toBeFalse()
        ->and($stripe->refund('stripe:pi_abc', 25000, 'XOF', 'deposit-refund-1'))->toBe('re_123');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/refunds'
        && $request['payment_intent'] === 'pi_abc' && (int) $request['amount'] === 25000
        && $request->header('Idempotency-Key')[0] === 'deposit-refund-1');
});
