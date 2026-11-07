<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments\Drivers;

use App\Domains\Billing\Payments\PaymentCheckout;
use App\Domains\Billing\Payments\PaymentDriver;
use App\Domains\Billing\Payments\PaymentFailed;
use App\Domains\Billing\Payments\PaymentGateway;
use App\Domains\Billing\Payments\VerifiedPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayDunya (checkout invoice): amounts in major units; the invoice token
 * identifies the payment; notifications carry a SHA-512 hash of the master
 * key and are then confirmed through the API.
 */
final class PayDunya implements PaymentDriver
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function start(PaymentCheckout $checkout, array $customer): array
    {
        $response = $this->client()->post($this->base().'/checkout-invoice/create', [
            'invoice' => ['total_amount' => (int) Amounts::major($checkout->amount_minor, $checkout->currency), 'description' => $customer['description']],
            'store' => ['name' => config('app.name')],
            'actions' => ['return_url' => $customer['return_url'], 'cancel_url' => $customer['return_url'], 'callback_url' => $customer['notify_url']],
            'custom_data' => ['reference' => $checkout->reference],
        ]);

        if (! $response->successful() || (string) $response->json('response_code') !== '00') {
            throw new PaymentFailed((string) ($response->json('response_text') ?? 'PayDunya refused the payment.'));
        }

        return ['url' => (string) $response->json('response_text'), 'token' => (string) $response->json('token')];
    }

    public function verify(PaymentCheckout $checkout): VerifiedPayment
    {
        if ($checkout->provider_token === null) {
            return new VerifiedPayment('pending');
        }

        $response = $this->client()->get($this->base().'/checkout-invoice/confirm/'.rawurlencode($checkout->provider_token));
        $status = (string) $response->json('status');

        return new VerifiedPayment(
            match ($status) {
                'completed' => 'paid',
                'cancelled', 'failed' => 'failed',
                default => 'pending',
            },
            $response->json('invoice.total_amount') !== null ? Amounts::fromMajor((string) $response->json('invoice.total_amount'), $checkout->currency) : null,
            $checkout->currency,
            'paydunya:'.$checkout->provider_token,
        );
    }

    public function notification(Request $request): ?array
    {
        $hash = (string) $request->input('data.hash');

        if ($hash === '' || ! hash_equals(hash('sha512', $this->gateway->credential('master_key')), $hash)) {
            return null;
        }

        $token = (string) $request->input('data.invoice.token');
        $reference = (string) $request->input('data.custom_data.reference');

        return $reference !== '' ? ['reference' => $reference] : ($token !== '' ? ['token' => $token] : null);
    }

    private function base(): string
    {
        return $this->gateway->mode === 'live' ? 'https://app.paydunya.com/api/v1' : 'https://app.paydunya.com/sandbox-api/v1';
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(20)->withHeaders([
            'PAYDUNYA-MASTER-KEY' => $this->gateway->credential('master_key'),
            'PAYDUNYA-PRIVATE-KEY' => $this->gateway->credential('private_key'),
            'PAYDUNYA-TOKEN' => $this->gateway->credential('token'),
        ]);
    }
}
