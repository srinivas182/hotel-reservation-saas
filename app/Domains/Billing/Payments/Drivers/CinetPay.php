<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments\Drivers;

use App\Domains\Billing\Payments\Payable;
use App\Domains\Billing\Payments\PaymentCredentials;
use App\Domains\Billing\Payments\PaymentDriver;
use App\Domains\Billing\Payments\PaymentFailed;
use App\Domains\Billing\Payments\VerifiedPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * CinetPay (checkout v2): amounts in major units (multiples of 5 in XOF);
 * notifications post the transaction id, which is then checked through
 * the /payment/check API.
 */
final class CinetPay implements PaymentDriver
{
    private const BASE = 'https://api-checkout.cinetpay.com/v2';

    public function __construct(private readonly PaymentCredentials $gateway) {}

    public function start(Payable $checkout, array $customer): array
    {
        $response = Http::acceptJson()->asJson()->timeout(20)->post(self::BASE.'/payment', [
            'apikey' => $this->gateway->credential('apikey'),
            'site_id' => $this->gateway->credential('site_id'),
            'transaction_id' => $checkout->reference,
            'amount' => (int) Amounts::major($checkout->amount_minor, $checkout->currency),
            'currency' => $checkout->currency,
            'description' => $customer['description'],
            'return_url' => $customer['return_url'],
            'notify_url' => $customer['notify_url'],
            'customer_name' => $customer['name'],
            // CinetPay asks for an address; without the payer's, the platform's sender address.
            'customer_email' => trim((string) ($customer['email'] ?? '')) !== '' ? $customer['email'] : (string) config('mail.from.address'),
            'channels' => 'ALL',
            'lang' => 'fr',
        ]);

        if (! $response->successful() || (string) $response->json('code') !== '201') {
            throw new PaymentFailed((string) ($response->json('description') ?? $response->json('message') ?? 'CinetPay refused the payment.'));
        }

        return ['url' => (string) $response->json('data.payment_url'), 'token' => (string) $response->json('data.payment_token')];
    }

    public function verify(Payable $checkout): VerifiedPayment
    {
        $response = Http::acceptJson()->asJson()->timeout(20)->post(self::BASE.'/payment/check', [
            'apikey' => $this->gateway->credential('apikey'),
            'site_id' => $this->gateway->credential('site_id'),
            'transaction_id' => $checkout->reference,
        ]);

        $status = (string) $response->json('data.status');

        return new VerifiedPayment(
            match ($status) {
                'ACCEPTED' => 'paid',
                'REFUSED', 'CANCELED', 'CANCELLED' => 'failed',
                default => 'pending',
            },
            $response->json('data.amount') !== null ? Amounts::fromMajor((string) $response->json('data.amount'), (string) $response->json('data.currency')) : null,
            $response->json('data.currency') !== null ? (string) $response->json('data.currency') : null,
            'cinetpay:'.$checkout->reference,
            (string) $response->json('message'),
            self::method($response->json('data.payment_method')),
        );
    }

    /**
     * CinetPay's channel codes as folio payment methods.
     */
    public static function method(mixed $code): ?string
    {
        $code = strtoupper((string) $code);

        return match (true) {
            $code === '' => null,
            str_starts_with($code, 'OM') || str_contains($code, 'ORANGE') => 'orange_money',
            str_starts_with($code, 'MOMO') || str_contains($code, 'MTN') => 'mtn_momo',
            str_starts_with($code, 'FLOOZ') || str_contains($code, 'MOOV') => 'moov_money',
            str_contains($code, 'WAVE') => 'wave',
            str_contains($code, 'VISA') || str_contains($code, 'MASTER') || str_contains($code, 'CARD') => 'card',
            default => null,
        };
    }

    public function notification(Request $request): ?array
    {
        $reference = (string) $request->input('cpm_trans_id');

        if ($reference === '' || (string) $request->input('cpm_site_id') !== $this->gateway->credential('site_id')) {
            return null;
        }

        return ['reference' => $reference];
    }
}
