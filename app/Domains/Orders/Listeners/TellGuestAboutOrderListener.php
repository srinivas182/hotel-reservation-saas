<?php

declare(strict_types=1);

namespace App\Domains\Orders\Listeners;

use App\Domains\Orders\Jobs\TellGuestAboutOrder;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\OrderChanged;
use App\Domains\Orders\OrderSource;
use App\Domains\Orders\OrderStatus;

final class TellGuestAboutOrderListener
{
    public function handle(OrderChanged $event): void
    {
        $order = Order::query()->find($event->orderId);

        if ($order === null || $order->source !== OrderSource::WhatsApp || $order->status === OrderStatus::New || $order->status === OrderStatus::Delivered) {
            return;
        }

        TellGuestAboutOrder::dispatch($order->id, $order->status->value);
    }
}
