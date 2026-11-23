<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments\Drivers;

use App\Domains\Billing\Payments\Payable;
use App\Domains\Billing\Payments\PaymentCredentials;
use App\Domains\Billing\Payments\PaymentDriver;
use App\Domains\Billing\Payments\PaymentFailed;
use App\Domains\Billing\Payments\VerifiedPayment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout (cards). A hosted Checkout Session per payment; the
 * session id is the provider token used to check it. Notifications are
 * signed with the endpoint's signing secret (Stripe-Signature, HMAC-SHA256
 * of "timestamp.body"), and only say which session to check.
 *
 * Amounts: Stripe takes the smallest unit, except for zero-decimal
 * currencies (XOF, XAF and others), which are sent in whole units.
 */
final class Stripe implements PaymentDriver
{
    public const BASE = 'https://api.stripe.com/v1';

    /** Currencies Stripe treats as having no decimals. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Seconds a signed notification stays valid (Stripe's default). */
    private const TOLERANCE = 300;

    public function __construct(private readonly PaymentCredentials $gateway) {}

    public function start(Payable $checkout, array $customer): array
    {
        $this->guardMode();

        // Without the payer's email, Stripe asks for it on its page (and sends its receipt there).
        $email = trim((string) ($customer['email'] ?? ''));

        $response = $this->http()->asForm()->post(self::BASE.'/checkout/sessions', [
            'mode' => 'payment',
            'client_reference_id' => $checkout->reference,
            ...($email !== '' ? ['customer_email' => $email] : []),
            'success_url' => $customer['return_url'],
            'cancel_url' => $customer['return_url'],
            'metadata' => ['reference' => $checkout->reference],
            'payment_intent_data' => ['metadata' => ['reference' => $checkout->reference], 'description' => mb_substr($customer['description'], 0, 300)],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($checkout->currency),
                    'unit_amount' => self::toStripe($checkout->amount_minor, $checkout->currency),
                    'product_data' => ['name' => mb_substr($customer['description'], 0, 250)],
                ],
            ]],
        ]);

        if (! $response->successful() || ! is_string($response->json('url'))) {
            throw new PaymentFailed((string) ($response->json('error.message') ?? 'Stripe refused the payment.'));
        }

        return ['url' => (string) $response->json('url'), 'token' => (string) $response->json('id')];
    }

    public function verify(Payable $checkout): VerifiedPayment
    {
        if ($checkout->provider_token === null || $checkout->provider_token === '') {
            return new VerifiedPayment('pending', message: 'No Stripe session yet.');
        }

        $response = $this->http()->get(self::BASE.'/checkout/sessions/'.rawurlencode($checkout->provider_token));

        if (! $response->successful()) {
            return new VerifiedPayment('pending', message: (string) $response->json('error.message'));
        }

        // A session belongs to one payment: never accept another reference's.
        if ((string) $response->json('client_reference_id') !== $checkout->reference) {
            return new VerifiedPayment('failed', message: 'The Stripe session belongs to another payment.');
        }

        $currency = strtoupper((string) $response->json('currency'));
        $status = match (true) {
            $response->json('payment_status') === 'paid' => 'paid',
            $response->json('status') === 'expired' => 'failed',
            default => 'pending',
        };

        return new VerifiedPayment(
            $status,
            self::fromStripe((int) $response->json('amount_total'), $currency),
            $currency,
            'stripe:'.($response->json('payment_intent') ?? $response->json('id')),
            method: 'card',
        );
    }

    public function notification(Request $request): ?array
    {
        $secret = $this->gateway->credential('webhook_secret');

        if ($secret === '' || ! self::validSignature($request->getContent(), (string) $request->header('Stripe-Signature'), $secret)) {
            return null;
        }

        $type = (string) $request->input('type');

        // Genuine but about something else: acknowledged, nothing to check.
        if (! in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'], true)) {
            return [];
        }

        return array_filter([
            'reference' => is_string($request->input('data.object.client_reference_id')) ? $request->input('data.object.client_reference_id') : null,
            'token' => is_string($request->input('data.object.id')) ? $request->input('data.object.id') : null,
        ]);
    }

    /**
     * Ask Stripe about the account: null when the key works, else why not.
     */
    public function check(): ?string
    {
        try {
            $this->guardMode();
            $response = $this->http()->get(self::BASE.'/balance');
        } catch (PaymentFailed $e) {
            return $e->getMessage();
        }

        return $response->successful() ? null : (string) ($response->json('error.message') ?? 'HTTP '.$response->status());
    }

    public static function validSignature(string $payload, string $header, string $secret, ?int $now = null): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function toStripe(int $minor, string $currency): int
    {
        $major = Amounts::major($minor, $currency);

        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true)
            ? (int) round((float) $major)
            : (int) round((float) $major * 100);
    }

    public static function fromStripe(int $amount, string $currency): int
    {
        return Amounts::fromMajor(in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? $amount : $amount / 100, $currency);
    }

    /** A test key in test mode, a live key in live mode: never mixed up. */
    private function guardMode(): void
    {
        $key = $this->gateway->credential('secret_key');
        $live = str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_');

        if ($key === '') {
            throw new PaymentFailed(__('The Stripe secret key is missing.'));
        }

        if ($live !== ($this->gateway->paymentMode() === 'live')) {
            throw new PaymentFailed(__('The Stripe key does not match the mode: use an sk_test_ key in test mode and an sk_live_ key in live mode.'));
        }
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->gateway->credential('secret_key'))->acceptJson()->timeout(20);
    }
}
