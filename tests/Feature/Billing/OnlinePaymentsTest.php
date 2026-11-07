<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Billing\CreditKind;
use App\Domains\Billing\Credits;
use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Payments\PaymentCheckout;
use App\Domains\Billing\Payments\PaymentGateway;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

const CREDENTIALS = [
    'paystack' => ['secret_key' => 'sk_test_abc'],
    'flutterwave' => ['secret_key' => 'FLWSECK_TEST-abc', 'webhook_hash' => 'flw-hash'],
    'cinetpay' => ['apikey' => 'cp-key', 'site_id' => '105', 'secret_key' => 'cp-secret'],
    'paydunya' => ['master_key' => 'pd-master', 'private_key' => 'test_private_x', 'token' => 'pd-token'],
];

function gatewayFor(string $driver, string $mode = 'test'): PaymentGateway
{
    return PaymentGateway::query()->create(['driver' => $driver, 'name' => ucfirst($driver), 'mode' => $mode, 'credentials' => CREDENTIALS[$driver], 'currencies' => ['XOF'], 'active' => true]);
}

/**
 * Fake each provider's API: starting gives a hosted page; checking says
 * paid for the given amount (in XOF).
 */
function fakeProviders(int $paidXof, string $status = 'paid'): void
{
    $ok = $status === 'paid';
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'ac']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => $ok ? 'success' : 'failed', 'amount' => $paidXof * 100, 'currency' => 'XOF', 'id' => 991]]),
        'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/abc']]),
        'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'success', 'data' => ['status' => $ok ? 'successful' : 'failed', 'amount' => $paidXof, 'currency' => 'XOF', 'id' => 77]]),
        'api-checkout.cinetpay.com/v2/payment/check' => Http::response(['code' => '00', 'data' => ['status' => $ok ? 'ACCEPTED' : 'REFUSED', 'amount' => (string) $paidXof, 'currency' => 'XOF']]),
        'api-checkout.cinetpay.com/v2/payment' => Http::response(['code' => '201', 'data' => ['payment_url' => 'https://checkout.cinetpay.com/payment/abc', 'payment_token' => 'cptok']]),
        'app.paydunya.com/sandbox-api/v1/checkout-invoice/create' => Http::response(['response_code' => '00', 'response_text' => 'https://app.paydunya.com/sandbox-checkout/invoice/test_abc', 'token' => 'test_abc']),
        'app.paydunya.com/sandbox-api/v1/checkout-invoice/confirm/*' => Http::response(['response_code' => '00', 'status' => $ok ? 'completed' : 'cancelled', 'invoice' => ['total_amount' => $paidXof]]),
    ]);
}

beforeEach(function (): void {
    Mail::fake();
    $this->travelTo('2026-11-01 08:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    BillingEntity::query()->create(['name' => 'CNH', 'legal_name' => 'CNH SARL', 'country_code' => 'CI', 'address' => 'Abidjan', 'email' => 'f@cnh.example', 'invoice_prefix' => 'CNH', 'tax_rules' => [['name' => 'TVA', 'rate_bps' => 1800]], 'is_default' => true]);
    subscribe($this->hotel, billingPlan('pro', [], null, 100, 50, 25000));
    $this->artisan('billing:invoice');
    $this->invoice = Invoice::query()->sole(); // 29 500 FCFA with TVA
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
});

it('pays an invoice online with each provider, confirmed server to server', function (string $driver): void {
    $gateway = gatewayFor($driver);
    fakeProviders(29500);

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.billing.invoices.pay', $this->invoice), ['gateway_id' => $gateway->id])
        ->assertRedirectContains('https://');
    $checkout = ($this->in)(fn () => PaymentCheckout::query()->sole());
    expect($checkout->amount_minor)->toBe(29500)->and($checkout->status)->toBe('pending');

    $this->get(route('payments.return', $checkout))->assertRedirect(route('hotel.billing'));

    expect(($this->in)(fn () => $checkout->fresh()->status))->toBe('paid')
        ->and($this->invoice->fresh()->status())->toBe('paid')
        ->and($this->invoice->fresh()->payments()->sole()->method)->toBe($driver);
})->with(['paystack', 'flutterwave', 'cinetpay', 'paydunya']);

it('refuses a confirmation for a different amount', function (): void {
    $gateway = gatewayFor('paystack');
    fakeProviders(100);

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.billing.invoices.pay', $this->invoice), ['gateway_id' => $gateway->id]);
    $checkout = ($this->in)(fn () => PaymentCheckout::query()->sole());
    $this->get(route('payments.return', $checkout));

    expect(($this->in)(fn () => $checkout->fresh()->status))->toBe('failed')->and($this->invoice->fresh()->balance())->toBe(29500);
});

it('accepts only genuine notifications, and counts a payment once', function (string $driver): void {
    $gateway = gatewayFor($driver);
    fakeProviders(29500);
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.billing.invoices.pay', $this->invoice), ['gateway_id' => $gateway->id]);
    $checkout = ($this->in)(fn () => PaymentCheckout::query()->sole());

    [$genuine, $forged] = match ($driver) {
        'paystack' => (function () use ($checkout): array {
            $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $checkout->reference]]);

            return [
                fn () => $this->call('POST', route('webhooks.payments', 'paystack'), [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', (string) $body, 'sk_test_abc'), 'CONTENT_TYPE' => 'application/json'], $body),
                fn () => $this->call('POST', route('webhooks.payments', 'paystack'), [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => 'forged', 'CONTENT_TYPE' => 'application/json'], $body),
            ];
        })(),
        'flutterwave' => [
            fn () => $this->postJson(route('webhooks.payments', 'flutterwave'), ['data' => ['tx_ref' => $checkout->reference]], ['verif-hash' => 'flw-hash']),
            fn () => $this->postJson(route('webhooks.payments', 'flutterwave'), ['data' => ['tx_ref' => $checkout->reference]], ['verif-hash' => 'nope']),
        ],
        'cinetpay' => [
            fn () => $this->post(route('webhooks.payments', 'cinetpay'), ['cpm_trans_id' => $checkout->reference, 'cpm_site_id' => '105']),
            fn () => $this->post(route('webhooks.payments', 'cinetpay'), ['cpm_trans_id' => $checkout->reference, 'cpm_site_id' => '999']),
        ],
        'paydunya' => [
            fn () => $this->post(route('webhooks.payments', 'paydunya'), ['data' => ['hash' => hash('sha512', 'pd-master'), 'invoice' => ['token' => 'test_abc'], 'custom_data' => ['reference' => $checkout->reference]]]),
            fn () => $this->post(route('webhooks.payments', 'paydunya'), ['data' => ['hash' => 'forged', 'custom_data' => ['reference' => $checkout->reference]]]),
        ],
    };

    $forged()->assertStatus(401);
    expect(($this->in)(fn () => $checkout->fresh()->status))->toBe('pending');

    $genuine()->assertOk();
    $genuine()->assertOk();
    $this->get(route('payments.return', $checkout));

    expect($this->invoice->fresh()->payments()->count())->toBe(1)->and($this->invoice->fresh()->status())->toBe('paid');
})->with(['paystack', 'flutterwave', 'cinetpay', 'paydunya']);

it('grants a credit pack only once it is paid, with its own invoice', function (): void {
    $gateway = gatewayFor('cinetpay');
    $pack = CreditPack::query()->create(['code' => 'ai-1000', 'name' => ['fr' => '1000 crédits IA'], 'kind' => 'ai', 'credits' => 1000, 'prices' => ['XOF' => 10000]]);
    fakeProviders(11800);
    $left = fn () => ($this->in)(fn () => app(Credits::class)->summary(CreditKind::Ai)['left']);
    $before = $left();

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.billing.packs.buy', $pack), ['gateway_id' => $gateway->id]);
    expect($left())->toBe($before);

    $checkout = ($this->in)(fn () => PaymentCheckout::query()->sole());
    expect($checkout->amount_minor)->toBe(11800);
    $this->get(route('payments.return', $checkout));

    $packInvoice = Invoice::query()->where('id', '!=', $this->invoice->id)->sole();
    expect($left())->toBe($before + 1000 * Credits::UNIT)
        ->and($packInvoice->total_minor)->toBe(11800)
        ->and($packInvoice->status())->toBe('paid');
});

it('lets the owner change plan and invoices an upgrade at once, and keeps managers out', function (): void {
    $complet = billingPlan('complet', ['restaurant', 'ordering', 'events'], null, 300, 200, 45000);
    $this->travelTo('2026-11-16 08:00:00');

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))->put(route('hotel.billing.plan'), ['plan_id' => $complet->id])->assertForbidden();
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.billing.plan'), ['plan_id' => $complet->id])->assertSessionHasNoErrors();

    expect(Invoice::query()->count())->toBe(2);
});

it('lets the platform configure every provider, with keys kept secret', function (): void {
    foreach (CREDENTIALS as $driver => $credentials) {
        $this->actingAs($this->admin)->post(route('platform.billing.gateways.store'), [
            'driver' => $driver, 'name' => ucfirst($driver), 'mode' => 'test', 'credentials' => $credentials, 'currencies' => ['XOF', 'EUR'], 'active' => true,
        ])->assertSessionHasNoErrors();
    }

    expect(PaymentGateway::query()->count())->toBe(4)
        ->and((string) DB::table('payment_gateways')->where('driver', 'paystack')->value('credentials'))->not->toContain('sk_test_abc');

    $paystack = PaymentGateway::query()->where('driver', 'paystack')->sole();
    $this->actingAs($this->admin)->put(route('platform.billing.gateways.update', $paystack), ['name' => 'Carte / Mobile money', 'mode' => 'live', 'credentials' => ['secret_key' => ''], 'currencies' => ['XOF'], 'active' => true])->assertSessionHasNoErrors();
    expect($paystack->fresh()->credential('secret_key'))->toBe('sk_test_abc')->and($paystack->fresh()->mode)->toBe('live');

    $this->actingAs($this->admin)->get(route('platform.billing.index'))->assertOk()->assertDontSee('sk_test_abc')->assertDontSee('pd-master');
    $this->actingAs(User::factory()->create())->post(route('platform.billing.gateways.store'), [])->assertForbidden();
});
