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
 * Paystack: amounts in the currency's subunit (× 100, also for XOF),
 * notifications signed with HMAC-SHA512 of the body and the secret key.
 */
final class Paystack implements PaymentDriver
{
    private const BASE = 'https://api.paystack.co';

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function start(PaymentCheckout $checkout, array $customer): array
    {
        $response = Http::withToken($this->gateway->credential('secret_key'))->acceptJson()->timeout(20)
            ->post(self::BASE.'/transaction/initialize', [
                'email' => $customer['email'],
                'amount' => (int) round((float) Amounts::major($checkout->amount_minor, $checkout->currency) * 100),
                'currency' => $checkout->currency,
                'reference' => $checkout->reference,
                'callback_url' => $customer['return_url'],
                'channels' => ['card', 'mobile_money'],
                'metadata' => ['description' => $customer['description']],
            ]);

        if (! $response->successful() || ! $response->json('status')) {
            throw new PaymentFailed((string) ($response->json('message') ?? 'Paystack refused the payment.'));
        }

        return ['url' => (string) $response->json('data.authorization_url'), 'token' => (string) $response->json('data.access_code')];
    }

    public function verify(PaymentCheckout $checkout): VerifiedPayment
    {
        $response = Http::withToken($this->gateway->credential('secret_key'))->acceptJson()->timeout(20)
            ->get(self::BASE.'/transaction/verify/'.rawurlencode($checkout->reference));

        if (! $response->successful()) {
            return new VerifiedPayment('pending', message: (string) $response->json('message'));
        }

        $status = (string) $response->json('data.status');

        return new VerifiedPayment(
            match ($status) {
                'success' => 'paid',
                'failed', 'abandoned', 'reversed' => 'failed',
                default => 'pending',
            },
            Amounts::fromMajor(((int) $response->json('data.amount')) / 100, (string) $response->json('data.currency')),
            (string) $response->json('data.currency'),
            'paystack:'.$response->json('data.id'),
        );
    }

    public function notification(Request $request): ?array
    {
        $signature = (string) $request->header('x-paystack-signature');

        if ($signature === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $this->gateway->credential('secret_key')), $signature)) {
            return null;
        }

        return $request->input('event') === 'charge.success' ? ['reference' => (string) $request->input('data.reference')] : null;
    }
}
