<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments;

/**
 * Where a payment driver reads its keys: the platform's gateway (hotel
 * subscriptions) or a hotel's own merchant account (guest deposits).
 */
interface PaymentCredentials
{
    public function credential(string $key): string;

    /** test or live. */
    public function paymentMode(): string;
}
