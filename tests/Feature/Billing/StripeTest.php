<?php

declare(strict_types=1);

use App\Domains\Billing\Payments\Drivers\Stripe;
use App\Domains\Billing\Payments\Payable;
use App\Domains\Billing\Payments\PaymentCredentials;
use App\Domains\Billing\Payments\PaymentFailed;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

function stripeCredentials(string $key = 'sk_test_x', string $mode = 'test'): PaymentCredentials
{
    return new class($key, $mode) implements PaymentCredentials
    {
        public function __construct(private string $key, private string $mode) {}

        public function credential(string $key): string
        {
            return ['secret_key' => $this->key, 'webhook_secret' => 'whsec_x'][$key] ?? '';
        }

        public function paymentMode(): string
        {
            return $this->mode;
        }
    };
}

it('sends zero-decimal currencies in whole units and others in cents', function (): void {
    expect(Stripe::toStripe(29500, 'XOF'))->toBe(29500)
        ->and(Stripe::toStripe(1999, 'EUR'))->toBe(1999)
        ->and(Stripe::fromStripe(29500, 'XOF'))->toBe(29500)
        ->and(Stripe::fromStripe(1999, 'USD'))->toBe(1999);
});

it('starts a hosted Checkout Session for the exact amount and reference', function (): void {
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_9', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_9'])]);

    $started = (new Stripe(stripeCredentials()))->start(new Payable('PAY-1', 29500, 'XOF'), [
        'email' => 'owner@example.com', 'name' => 'Owner', 'description' => 'Invoice CNH-1', 'return_url' => 'https://app.test/back', 'notify_url' => 'https://app.test/hook',
    ]);

    expect($started)->toBe(['url' => 'https://checkout.stripe.com/c/pay/cs_test_9', 'token' => 'cs_test_9']);
    Http::assertSent(fn (ClientRequest $r): bool => $r['client_reference_id'] === 'PAY-1'
        && $r['line_items'][0]['price_data']['unit_amount'] === 29500
        && $r['line_items'][0]['price_data']['currency'] === 'xof'
        && $r->hasHeader('Authorization', 'Bearer sk_test_x'));
});

it('never mixes a live key with test mode', function (): void {
    Http::fake();

    expect(fn () => (new Stripe(stripeCredentials('sk_live_x', 'test')))->start(new Payable('PAY-1', 100, 'EUR'), [
        'email' => 'a@b.c', 'name' => 'A', 'description' => 'x', 'return_url' => 'https://a', 'notify_url' => 'https://a',
    ]))->toThrow(PaymentFailed::class);
    Http::assertNothingSent();
});

it('refuses a session that belongs to another payment', function (): void {
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(['id' => 'cs_1', 'payment_status' => 'paid', 'amount_total' => 100, 'currency' => 'eur', 'client_reference_id' => 'OTHER'])]);

    expect((new Stripe(stripeCredentials()))->verify(new Payable('PAY-1', 100, 'EUR', 'cs_1'))->status)->toBe('failed');
});

it('checks signatures, rejects old ones, and acknowledges other events', function (): void {
    $driver = new Stripe(stripeCredentials());
    $notice = function (array $event, ?int $at = null, string $secret = 'whsec_x') use ($driver): ?array {
        $body = (string) json_encode($event);
        $at ??= time();
        $request = Request::create('/webhooks/payments/stripe', 'POST', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$body, $secret), 'CONTENT_TYPE' => 'application/json'], $body);

        return $driver->notification($request);
    };
    $completed = ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'client_reference_id' => 'PAY-1']]];

    expect($notice($completed))->toBe(['reference' => 'PAY-1', 'token' => 'cs_1'])
        ->and($notice($completed, secret: 'whsec_other'))->toBeNull()
        ->and($notice($completed, time() - 3600))->toBeNull()
        ->and($notice(['type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1']]]))->toBe([]);
});
