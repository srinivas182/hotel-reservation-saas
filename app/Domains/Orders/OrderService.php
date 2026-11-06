<?php

declare(strict_types=1);

namespace App\Domains\Orders;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Finance\Taxes\TaxCalculator;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Finance\Taxes\TaxScope;
use App\Domains\Orders\Exceptions\OrderNotPossible;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderLine;
use App\Domains\Restaurant\Menu\MenuCatalog;
use App\Domains\Restaurant\Menu\MenuItem;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Food and drink orders: placed with today's menu prices (copied onto the
 * order), then accepted with a waiting time, ready and delivered.
 */
final class OrderService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MenuCatalog $catalog,
        private readonly TaxCalculator $taxes,
    ) {}

    public function place(NewOrder $data, ?User $by = null): Order
    {
        $hotel = $this->context->hotel();
        $outlet = Outlet::query()->where('active', true)->where('orders_enabled', true)->find($data->outletId) ?? throw OrderNotPossible::because('closed');
        $items = array_values(array_filter($data->items, fn (array $i): bool => $i['quantity'] > 0));

        if ($items === []) {
            throw OrderNotPossible::because('no_items');
        }

        $booking = $data->bookingId === null ? null : Booking::query()->find($data->bookingId);

        if ($data->payment === OrderPayment::RoomCharge && ($booking === null || $booking->status !== BookingStatus::CheckedIn)) {
            throw OrderNotPossible::because('not_staying');
        }

        if ($data->delivery === OrderDelivery::Room && trim((string) $data->roomNumber) === '') {
            throw OrderNotPossible::because('no_room');
        }

        $now = CarbonImmutable::now($hotel->timezone);
        $rates = TaxRate::query()->where('active', true)->get();
        $dishes = MenuItem::query()->with('section')->whereIn('id', array_column($items, 'menu_item_id'))->get()->keyBy('id');
        $period = $this->catalog->currentPeriod($outlet, $now);
        $lines = [];

        foreach ($items as $item) {
            /** @var MenuItem|null $dish */
            $dish = $dishes->get($item['menu_item_id']);

            if ($dish === null || $dish->section?->outlet_id !== $outlet->id || ! $dish->orderable || ! $dish->availableToday($hotel) || ! $dish->servedAt($period?->name)) {
                throw OrderNotPossible::because('not_orderable');
            }

            $unit = Money::ofMinor($dish->price_minor, $hotel->currency);
            $line = $unit->multipliedBy($item['quantity']);
            $lines[] = ['dish' => $dish, 'quantity' => $item['quantity'], 'net' => $line, 'paid' => $this->taxes->calculate($rates, $line, TaxScope::FoodBeverage, $now)];
        }

        $order = DB::transaction(function () use ($data, $outlet, $booking, $lines, $hotel, $by): Order {
            // Total = what the guest pays; tax = all food and drink tax in it (included or added).
            $total = Money::zero($hotel->currency);
            $tax = Money::zero($hotel->currency);
            foreach ($lines as $line) {
                $total = $total->plus($line['paid']->total);
                $tax = $tax->plus($line['paid']->totalTax());
            }

            $order = Order::query()->create([
                'outlet_id' => $outlet->id,
                'booking_id' => $booking?->id,
                'guest_id' => $data->guestId ?? $booking?->guest_id,
                'conversation_id' => $data->conversationId,
                'reference' => $this->nextReference(),
                'status' => OrderStatus::New,
                'source' => $data->source,
                'delivery' => $data->delivery,
                'room_number' => $data->delivery === OrderDelivery::Room ? trim((string) $data->roomNumber) : null,
                'location_note' => $data->locationNote,
                'payment' => $data->payment,
                'subtotal_minor' => $total->minus($tax)->getMinorAmount()->toInt(),
                'tax_minor' => $tax->getMinorAmount()->toInt(),
                'total_minor' => $total->getMinorAmount()->toInt(),
                'currency' => $hotel->currency,
                'notes' => $data->notes,
                'created_by' => $by?->getKey(),
            ]);

            foreach ($lines as $line) {
                OrderLine::query()->create([
                    'order_id' => $order->id,
                    'menu_item_id' => $line['dish']->id,
                    'name' => $line['dish']->localizedName($hotel->default_locale, $hotel->default_locale),
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => intdiv($line['paid']->total->getMinorAmount()->toInt(), $line['quantity']),
                    'total_minor' => $line['paid']->total->getMinorAmount()->toInt(),
                ]);
            }

            return $order;
        });

        Audit::record('orders.placed', 'Order placed', subject: $order, causer: $by, properties: [
            'reference' => $order->reference, 'total_minor' => $order->total_minor, 'payment' => $order->payment->value, 'source' => $order->source->value,
        ]);
        OrderChanged::dispatch($order->id);

        return $order;
    }

    public function move(Order $order, OrderStatus $to, ?User $by = null, ?int $etaMinutes = null, ?string $reason = null): Order
    {
        $from = $order->status;

        if (! in_array($to, $from->allowedNext(), true)) {
            throw OrderNotPossible::because('status');
        }

        $order->forceFill([
            'status' => $to,
            ...match ($to) {
                OrderStatus::Accepted => ['accepted_at' => now(), 'eta_minutes' => $etaMinutes],
                OrderStatus::Ready => ['ready_at' => now()],
                OrderStatus::Delivered => ['delivered_at' => now(), 'closed_at' => now()],
                OrderStatus::Declined => ['decline_reason' => $reason, 'closed_at' => now()],
                OrderStatus::Cancelled => ['decline_reason' => $reason, 'closed_at' => now()],
                default => [],
            },
        ])->save();

        Audit::record('orders.'.$to->value, 'Order: '.$to->label(), subject: $order, causer: $by, properties: [
            'reference' => $order->reference, 'from' => $from->value, 'eta_minutes' => $etaMinutes, 'reason' => $reason,
        ]);
        OrderChanged::dispatch($order->id);

        return $order;
    }

    private function nextReference(): string
    {
        $hotel = $this->context->hotel();
        $year = (int) now($hotel->timezone)->format('Y');

        DB::table('order_sequences')->insertOrIgnore(['hotel_id' => $hotel->getKey(), 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('order_sequences')->where('hotel_id', $hotel->getKey())->where('year', $year)->lockForUpdate()->first(['id', 'last_number']);
        $number = (int) ($row->last_number ?? 0) + 1;
        DB::table('order_sequences')->where('id', $row->id ?? 0)->update(['last_number' => $number, 'updated_at' => now()]);

        return sprintf('O-%s-%04d', substr((string) $year, -2), $number);
    }
}
