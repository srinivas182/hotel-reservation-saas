<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments;

final readonly class VerifiedPayment
{
    public function __construct(
        public string $status, // paid, pending, failed
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $providerReference = null,
        public ?string $message = null,
        // Sprint F: how the payer paid, as a folio method (wave, orange_money…), when the provider says.
        public ?string $method = null,
    ) {}
}
