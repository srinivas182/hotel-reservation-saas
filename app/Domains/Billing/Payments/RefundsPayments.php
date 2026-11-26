<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments;

/**
 * 0.39.1: a provider that can send a payment back to the payer's card or
 * wallet from our side. Mobile money through CinetPay cannot: those
 * refunds stay manual.
 */
interface RefundsPayments
{
    /**
     * Refund a payment (all or part). The idempotency key makes a repeated
     * call (double click, retry) refund only once.
     *
     * @return string the provider's refund id
     *
     * @throws PaymentFailed when the provider refuses
     */
    public function refund(string $providerReference, int $amountMinor, string $currency, string $idempotencyKey): string;

    /** Whether this payment reference can be refunded from our side. */
    public function canRefund(?string $providerReference): bool;
}
