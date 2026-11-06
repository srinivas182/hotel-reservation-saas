<?php

declare(strict_types=1);

namespace App\Domains\Restaurant;

use Carbon\CarbonImmutable;

final readonly class NewTableBooking
{
    /**
     * @param  array{first_name: string, last_name?: string|null, phone?: string|null, email?: string|null, preferred_locale?: string|null}  $guest
     */
    public function __construct(
        public string $outletId,
        public CarbonImmutable $date,
        public string $time,
        public int $covers,
        public array $guest,
        public TableBookingSource $source,
        public TableBookingStatus $status = TableBookingStatus::Confirmed,
        public ?string $notes = null,
        public ?string $conversationId = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
