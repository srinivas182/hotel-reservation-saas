<?php

declare(strict_types=1);

namespace App\Domains\Deposits;

use App\Domains\Deposits\Models\MerchantAccount;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * "Test the keys" (Sprint F): asks the provider about a payment that does
 * not exist. A refusal of the keys themselves is reported; any other answer
 * means the provider knows the account.
 *
 * Returns null when the keys work, or the reason they do not.
 */
final class MerchantCheck
{
    /** CinetPay codes for an unknown API key or site id. */
    private const CINETPAY_BAD_KEYS = ['608', '609', '613'];

    public function run(MerchantAccount $account): ?string
    {
        if ($account->driver === 'simulation') {
            return null;
        }

        try {
            $response = Http::acceptJson()->asJson()->timeout(15)->post('https://api-checkout.cinetpay.com/v2/payment/check', [
                'apikey' => $account->credential('apikey'),
                'site_id' => $account->credential('site_id'),
                'transaction_id' => 'CHECK'.now()->format('YmdHis'),
            ]);
        } catch (Throwable $e) {
            return mb_substr(__('The provider could not be reached: :error', ['error' => $e->getMessage()]), 0, 300);
        }

        $code = (string) $response->json('code');

        if ($response->serverError() || in_array($code, self::CINETPAY_BAD_KEYS, true)) {
            return mb_substr((string) ($response->json('message') ?? $response->json('description') ?? 'HTTP '.$response->status()), 0, 300);
        }

        return null;
    }
}
