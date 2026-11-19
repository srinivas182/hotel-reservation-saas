<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments;

/**
 * What a payment driver needs to start or check one payment: our
 * reference (the provider's transaction id), the amount and, for some
 * providers, the token they gave back when it started.
 */
final readonly class Payable
{
    public function __construct(
        public string $reference,
        public int $amount_minor,
        public string $currency,
        public ?string $provider_token = null,
    ) {}
}
