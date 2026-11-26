<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Drivers;

use App\Domains\Billing\Payments\Payable;
use App\Domains\Billing\Payments\PaymentDriver;
use App\Domains\Billing\Payments\RefundsPayments;
use App\Domains\Billing\Payments\VerifiedPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * A pretend mobile-money provider for staging and demos (simulation mode
 * only): its checkout page lets you choose Wave, Orange Money… and pay or
 * refuse, and the check reads back what you chose.
 */
final class SimulatedDeposits implements PaymentDriver, RefundsPayments
{
    public function start(Payable $checkout, array $customer): array
    {
        return ['url' => route('deposits.simulate', $checkout->reference), 'token' => 'sim-'.$checkout->reference];
    }

    public function verify(Payable $checkout): VerifiedPayment
    {
        /** @var array{status?: string, method?: string}|null $outcome */
        $outcome = Cache::get(self::key($checkout->reference));

        return match ($outcome['status'] ?? null) {
            'paid' => new VerifiedPayment('paid', $checkout->amount_minor, $checkout->currency, 'sim:'.$checkout->reference, 'Simulated payment', $outcome['method'] ?? 'wave'),
            'failed' => new VerifiedPayment('failed', message: 'Simulated refusal'),
            default => new VerifiedPayment('pending'),
        };
    }

    public function notification(Request $request): ?array
    {
        return null;
    }

    public function canRefund(?string $providerReference): bool
    {
        return is_string($providerReference) && str_starts_with($providerReference, 'sim:');
    }

    /** 0.39.1: a pretend refund, so staging can show the whole flow. */
    public function refund(string $providerReference, int $amountMinor, string $currency, string $idempotencyKey): string
    {
        return 'sim-refund-'.substr(hash('sha256', $idempotencyKey), 0, 12);
    }

    /**
     * @param  'paid'|'failed'  $status
     */
    public static function record(string $reference, string $status, string $method): void
    {
        Cache::put(self::key($reference), ['status' => $status, 'method' => $method], now()->addDay());
    }

    private static function key(string $reference): string
    {
        return 'deposit-sim:'.$reference;
    }
}
