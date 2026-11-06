<?php

declare(strict_types=1);

namespace App\Domains\Orders;

final readonly class NewOrder
{
    /**
     * @param  list<array{menu_item_id: int, quantity: int}>  $items
     */
    public function __construct(
        public string $outletId,
        public array $items,
        public OrderSource $source,
        public OrderDelivery $delivery,
        public OrderPayment $payment,
        public ?string $bookingId = null,
        public ?string $guestId = null,
        public ?string $conversationId = null,
        public ?string $roomNumber = null,
        public ?string $locationNote = null,
        public ?string $notes = null,
    ) {}
}
