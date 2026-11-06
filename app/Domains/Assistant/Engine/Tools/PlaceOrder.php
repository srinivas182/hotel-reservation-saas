<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Conversations\Models\Conversation;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Orders\Exceptions\OrderNotPossible;
use App\Domains\Orders\NewOrder;
use App\Domains\Orders\OrderDelivery;
use App\Domains\Orders\OrderPayment;
use App\Domains\Orders\OrderService;
use App\Domains\Orders\OrderSource;
use App\Domains\Orders\Stays;
use App\Domains\Restaurant\Menu\MenuCatalog;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;

/**
 * Food and drink orders on WhatsApp. First call with guest_agreed=false to
 * get the order and total to repeat to the guest; only after the guest says
 * yes, call again with guest_agreed=true to send it to the kitchen.
 */
final class PlaceOrder extends AssistantTool
{
    public function name(): string
    {
        return 'place_order';
    }

    public function description(): string
    {
        return 'Order food or drinks. Step 1: call with guest_agreed=false to get the exact dishes and total; repeat them to the guest and ask for a yes. Step 2: only after the guest agrees, call again with the same items and guest_agreed=true. The kitchen then accepts the order; never say it is accepted.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'outlet' => $schema->string()->description('Outlet code (empty: room service, else the first outlet taking orders)'),
            'items' => $schema->array()->items($schema->object([
                'dish' => $schema->string()->description('Dish name as on the menu')->required(),
                'quantity' => $schema->integer()->required(),
            ]))->required(),
            'delivery' => $schema->string()->enum(['room', 'outlet'])->description('To the guest\'s room (guests staying only) or at the outlet')->required(),
            'location' => $schema->string()->description('For delivery at the outlet: table or place (e.g. pool)'),
            'payment' => $schema->string()->enum(['room_charge', 'on_delivery'])->description('On the room bill (guests staying only) or paid on delivery')->required(),
            'notes' => $schema->string()->description('Requests (no ice, well done…)'),
            'guest_agreed' => $schema->boolean()->description('True only after the guest said yes to the order and total')->required(),
        ];
    }

    protected function run(array $arguments): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $outlets = Outlet::query()->where('active', true)->where('orders_enabled', true)->orderBy('sort_order')->get();
        $outlet = $outlets->firstWhere('public_slug', (string) ($arguments['outlet'] ?? ''))
            ?? $outlets->first(fn (Outlet $o): bool => $o->type->value === 'room_service')
            ?? $outlets->first();

        if ($outlet === null) {
            return ['ordered' => false, 'error' => 'The hotel does not take orders on WhatsApp.'];
        }

        $conversation = $this->context->isPractice() ? null : Conversation::query()->with('contact.guest')->find($this->context->conversationId);
        $stay = $conversation === null ? null : app(Stays::class)->forPhone('+'.$conversation->contact?->wa_id);
        $settings = RestaurantSettings::current();

        if ($stay === null && ! $settings->accept_outside_orders && ! $this->context->isPractice()) {
            return ['ordered' => false, 'reason' => 'outside_guests', 'message' => 'Orders are only for guests staying at the hotel.'];
        }

        // Match the dishes against what can be ordered now.
        $menu = app(MenuCatalog::class)->forGuests($outlet, $this->context->locale);
        $available = collect($menu['sections'])->flatMap(fn (array $s) => $s['items']);
        $items = [];
        $unknown = [];
        $total = Money::zero($hotel->currency);
        $summary = [];

        foreach ((array) ($arguments['items'] ?? []) as $wanted) {
            $name = Str::lower(Str::ascii((string) ($wanted['dish'] ?? '')));
            $quantity = max(0, min(50, (int) ($wanted['quantity'] ?? 0)));
            $dish = $available->first(fn (array $d): bool => Str::lower(Str::ascii($d['name'])) === $name)
                ?? $available->first(fn (array $d): bool => $name !== '' && str_contains(Str::lower(Str::ascii($d['name'])), $name));

            if ($dish === null || $quantity === 0) {
                $unknown[] = (string) ($wanted['dish'] ?? '');

                continue;
            }

            $line = $dish['price']->multipliedBy($quantity);
            $total = $total->plus($line);
            $this->context->allowMoney($dish['price']);
            $this->context->allowMoney($line);
            $items[] = ['menu_item_id' => $dish['id'], 'quantity' => $quantity];
            $summary[] = $quantity.' × '.$dish['name'].' = '.MoneyFormatter::format($line, $this->context->locale);
        }

        if ($unknown !== [] || $items === []) {
            return ['ordered' => false, 'reason' => 'unknown_dishes', 'not_found' => $unknown, 'can_order' => $available->pluck('name')->values()->all()];
        }

        $this->context->allowMoney($total);
        $delivery = (string) ($arguments['delivery'] ?? 'room') === 'outlet' ? OrderDelivery::Outlet : OrderDelivery::Room;
        $payment = (string) ($arguments['payment'] ?? 'on_delivery') === 'room_charge' ? OrderPayment::RoomCharge : OrderPayment::OnDelivery;

        if ($stay === null && ! $this->context->isPractice() && ($delivery === OrderDelivery::Room || $payment === OrderPayment::RoomCharge)) {
            return ['ordered' => false, 'reason' => 'not_staying', 'message' => 'This number is not a guest staying at the hotel: offer delivery at the outlet, paid on delivery.'];
        }

        $preview = [
            'dishes' => $summary,
            'total' => MoneyFormatter::format($total, $this->context->locale),
            'delivery' => $delivery === OrderDelivery::Room ? 'room '.implode(', ', $stay === null ? [] : Stays::roomNumbers($stay)) : (string) ($arguments['location'] ?? $outlet->localizedName($this->context->locale, $hotel->default_locale)),
            'payment' => $payment->value,
        ];

        if (! (bool) ($arguments['guest_agreed'] ?? false)) {
            return ['ordered' => false, 'preview' => $preview, 'next_step' => 'Repeat the dishes and the total to the guest and ask them to confirm. Only then call again with guest_agreed=true.'];
        }

        if ($this->context->isPractice()) {
            return ['ordered' => false, 'practice' => true, 'preview' => $preview, 'note' => 'Practice screen: no order was sent.'];
        }

        try {
            $order = app(OrderService::class)->place(new NewOrder(
                outletId: $outlet->id,
                items: $items,
                source: OrderSource::WhatsApp,
                delivery: $delivery,
                payment: $payment,
                bookingId: $stay?->id,
                guestId: $conversation?->contact?->guest?->id,
                conversationId: $conversation?->id,
                roomNumber: $delivery === OrderDelivery::Room && $stay !== null ? (Stays::roomNumbers($stay)[0] ?? null) : null,
                locationNote: $delivery === OrderDelivery::Outlet ? ((string) ($arguments['location'] ?? '') ?: null) : null,
                notes: trim((string) ($arguments['notes'] ?? '')) ?: null,
            ));
        } catch (OrderNotPossible $e) {
            return ['ordered' => false, 'reason' => $e->reason, 'message' => $e->getMessage()];
        }

        app(StaffAlerts::class)->notify(AlertKind::NewOrder, $order->reference.' · '.$preview['delivery'].' · '.$preview['total'], $order->id);

        return ['ordered' => true, 'reference' => $order->reference, 'total' => $preview['total'], 'next_step' => 'Tell the guest the order was sent to the kitchen, which will confirm the time. Do NOT say it is accepted.'];
    }
}
