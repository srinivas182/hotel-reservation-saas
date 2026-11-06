<?php

declare(strict_types=1);

namespace App\Domains\Orders\Jobs;

use App\Domains\Audit\Audit;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\OrderDelivery;
use App\Domains\Orders\OrderStatus;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells a WhatsApp guest what happened to their order: accepted (with the
 * waiting time), declined, cancelled, or ready to collect.
 */
final class TellGuestAboutOrder implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $orderId, public readonly string $status) {}

    public function handle(MessageSender $sender, TenantContext $context): void
    {
        $order = Order::query()->with(['guest', 'outlet'])->find($this->orderId);

        if ($order === null || $order->conversation_id === null || $order->status->value !== $this->status) {
            return;
        }

        $hotel = $context->hotel();
        $locale = $order->guest->preferred_locale ?? $hotel->default_locale;
        $v = [
            ':reference' => $order->reference,
            ':minutes' => (string) $order->eta_minutes,
            ':total' => MoneyFormatter::format(Money::ofMinor($order->total_minor, $order->currency), $locale),
            ':outlet' => (string) $order->outlet?->localizedName($locale, $hotel->default_locale),
            ':reason' => (string) $order->decline_reason,
        ];

        $text = match ($order->status) {
            OrderStatus::Accepted => (string) __('Your order :reference is accepted. It will be ready in about :minutes minutes. Total: :total.', locale: $locale),
            OrderStatus::Declined => (string) __('We are sorry, your order :reference cannot be prepared. :reason', locale: $locale),
            OrderStatus::Cancelled => (string) __('Your order :reference has been cancelled. :reason', locale: $locale),
            OrderStatus::Ready => $order->delivery === OrderDelivery::Outlet ? (string) __('Your order :reference is ready at :outlet.', locale: $locale) : null,
            default => null,
        };

        $conversation = Conversation::query()->find($order->conversation_id);

        if ($text === null || $conversation === null) {
            return;
        }

        try {
            $sender->sendText($conversation, trim(strtr($text, $v)), null, MessageSource::System);
            Audit::record('orders.guest_told', 'The guest was told about the order on WhatsApp', subject: $order, properties: ['status' => $order->status->value]);
        } catch (CannotSend) {
            $conversation->forceFill(['needs_attention' => true, 'handover_reason' => 'order_guest_not_told:'.$order->status->value])->save();
        }
    }
}
