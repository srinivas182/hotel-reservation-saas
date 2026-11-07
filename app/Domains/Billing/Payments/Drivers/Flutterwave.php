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
 * Flutterwave (v3 Standard): amounts in major units; notifications carry
 * the secret hash set in the Flutterwave dashboard ("verif-hash").
 */
final class Flutterwave implements PaymentDriver
{
    private const BASE = 'https://api.flutterwave.com/v3';

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function start(PaymentCheckout $checkout, array $customer): array
    {
        $response = Http::withToken($this->gateway->credential('secret_key'))->acceptJson()->timeout(20)
            ->post(self::BASE.'/payments', [
                'tx_ref' => $checkout->reference,
                'amount' => Amounts::major($checkout->amount_minor, $checkout->currency),
                'currency' => $checkout->currency,
                'redirect_url' => $customer['return_url'],
                'customer' => ['email' => $customer['email'], 'name' => $customer['name']],
                'customizations' => ['title' => $customer['description']],
            ]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new PaymentFailed((string) ($response->json('message') ?? 'Flutterwave refused the payment.'));
        }

        return ['url' => (string) $response->json('data.link'), 'token' => null];
    }

    public function verify(PaymentCheckout $checkout): VerifiedPayment
    {
        $response = Http::withToken($this->gateway->credential('secret_key'))->acceptJson()->timeout(20)
            ->get(self::BASE.'/transactions/verify_by_reference', ['tx_ref' => $checkout->reference]);

        if (! $response->successful()) {
            return new VerifiedPayment('pending', message: (string) $response->json('message'));
        }

        $status = (string) $response->json('data.status');

        return new VerifiedPayment(
            match ($status) {
                'successful' => 'paid',
                'failed', 'cancelled' => 'failed',
                default => 'pending',
            },
            Amounts::fromMajor((string) $response->json('data.amount'), (string) $response->json('data.currency')),
            (string) $response->json('data.currency'),
            'flutterwave:'.$response->json('data.id'),
        );
    }

    public function notification(Request $request): ?array
    {
        $hash = $this->gateway->credential('webhook_hash');

        if ($hash === '' || ! hash_equals($hash, (string) $request->header('verif-hash'))) {
            return null;
        }

        $reference = (string) ($request->input('data.tx_ref') ?? $request->input('txRef') ?? '');

        return $reference === '' ? null : ['reference' => $reference];
    }
}
