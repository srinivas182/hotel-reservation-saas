<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

use App\Domains\Rates\Quote\StayRequest;
use Brick\Money\Money;

/**
 * Everything needed to create a booking.
 *
 * @phpstan-type GuestData array{first_name: string, last_name?: string|null, phone?: string|null, email?: string|null, preferred_locale?: string|null}
 */
final readonly class NewBooking
{
    /**
     * @param  GuestData  $guest
     */
    public function __construct(
        public StayRequest $stay,
        public array $guest,
        public BookingChannel $channel,
        public BookingStatus $status = BookingStatus::Confirmed,
        public ?string $externalReference = null,
        public ?string $groupLabel = null,
        public ?string $notes = null,
        public ?string $paymentNote = null,
        public ?Money $overrideTotal = null,
        public ?string $overrideReason = null,
    ) {}
}
