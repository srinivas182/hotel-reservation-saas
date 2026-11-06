<?php

declare(strict_types=1);

namespace App\Domains\Folio\Listeners;

use App\Domains\Folio\Folio;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\OrderChanged;

final class PostDeliveredOrder
{
    public function __construct(private readonly Folio $folio) {}

    public function handle(OrderChanged $event): void
    {
        $order = Order::query()->find($event->orderId);

        if ($order !== null) {
            $this->folio->postOrder($order);
        }
    }
}
