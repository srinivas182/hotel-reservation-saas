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
    ) {}
}
