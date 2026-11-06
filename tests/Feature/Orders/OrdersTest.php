<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Orders\Exceptions\OrderNotPossible;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\StayPhone;
use App\Domains\Orders\NewOrder;
use App\Domains\Orders\OrderDelivery;
use App\Domains\Orders\OrderPayment;
use App\Domains\Orders\OrderService;
use App\Domains\Orders\OrderSource;
use App\Domains\Orders\OrderStatus;
use App\Domains\Orders\Stays;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-13 20:00:00'); // during dinner service
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $this->category = roomCategory($this->hotel, 'EXE', 3);
    rateFor($this->hotel, $this->category);
    [$this->outlet, $this->poulet, $this->flag] = roomService($this->hotel);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->place = fn (array $overrides = []) => ($this->in)(fn () => app(OrderService::class)->place(new NewOrder(...[
        'outletId' => $this->outlet->id,
        'items' => [['menu_item_id' => $this->poulet->id, 'quantity' => 2], ['menu_item_id' => $this->flag->id, 'quantity' => 1]],
        'source' => OrderSource::Phone,
        'delivery' => OrderDelivery::Room,
        'payment' => OrderPayment::OnDelivery,
        'roomNumber' => '104',
        ...$overrides,
    ])));
});

it('recognises guests staying by their phone or an extra number on the stay', function (): void {
    $stay = checkedIn($this->hotel, $this->category);
    $future = book($this->hotel, $this->category, '2026-11-20', '2026-11-21', phone: '+2250700000003');
    ($this->in)(fn () => StayPhone::query()->create(['booking_id' => $stay->id, 'phone' => '+2250700000002']));

    $find = fn (string $phone) => ($this->in)(fn () => app(Stays::class)->forPhone($phone)?->id);
    expect($find('+225 07 00 00 00 01'))->toBe($stay->id)
        ->and($find('+2250700000002'))->toBe($stay->id)
        ->and($find('+2250700000003'))->toBeNull()
        ->and($find('+2250700000099'))->toBeNull();

    ($this->in)(fn () => app(BookingLifecycle::class)->checkOut($stay));
    expect($find('+2250700000001'))->toBeNull();
});

it('places an order with today\'s prices and taxes, copied onto the order', function (): void {
    ($this->in)(fn () => TaxRate::query()->create(['name' => ['fr' => 'TVA'], 'type' => 'percentage', 'percentage_bps' => 1000, 'inclusive' => false, 'applies_to' => ['food_beverage'], 'active' => true]));

    $order = ($this->place)();
    ($this->in)(fn () => $this->poulet->update(['price_minor' => 9000]));

    $order = ($this->in)(fn () => $order->fresh('lines'));
    expect($order->reference)->toBe('O-26-0001')
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->total_minor)->toBe(12100)
        ->and($order->tax_minor)->toBe(1100)
        ->and($order->lines->pluck('total_minor')->all())->toBe([11000, 1100])
        ->and($order->lines->first()->name)->toBe('Poulet braisé');
});

it('refuses orders that cannot be made', function (array $overrides, string $reason): void {
    if ($reason === 'not_orderable') {
        ($this->in)(fn () => $this->flag->update(['orderable' => false]));
    }
    if ($reason === 'closed') {
        ($this->in)(fn () => $this->outlet->update(['orders_enabled' => false]));
    }

    expect(fn () => ($this->place)($overrides))->toThrow(fn (OrderNotPossible $e) => expect($e->reason)->toBe($reason));
})->with([
    'not orderable' => [[], 'not_orderable'],
    'ordering off' => [[], 'closed'],
    'room charge without a stay' => [['payment' => OrderPayment::RoomCharge], 'not_staying'],
    'no room' => [['roomNumber' => ''], 'no_room'],
    'nothing' => [['items' => [['menu_item_id' => 1, 'quantity' => 0]]], 'no_items'],
]);

it('charges to the room only for a guest checked in', function (): void {
    $stay = checkedIn($this->hotel, $this->category);

    $order = ($this->place)(['payment' => OrderPayment::RoomCharge, 'bookingId' => $stay->id]);

    expect($order->payment)->toBe(OrderPayment::RoomCharge)->and($order->guest_id)->toBe($stay->guest_id);
});

it('moves orders from new to delivered on the board, with a waiting time, audited', function (): void {
    $chef = staffMember($this->hotel, SystemRole::RestaurantBar);
    $order = ($this->place)();

    asStaffOf($this->hotel, $chef)->post(route('hotel.restaurant.orders.status', $order), ['status' => 'accepted'])->assertSessionHasErrors('eta_minutes');
    asStaffOf($this->hotel, $chef)->post(route('hotel.restaurant.orders.status', $order), ['status' => 'accepted', 'eta_minutes' => 20])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $chef)->get(route('hotel.restaurant.orders'))->assertInertia(fn ($page) => $page->where('columns.1.orders.0.eta_minutes', 20)->where('columns.1.orders.0.where', 'Chambre 104'));
    asStaffOf($this->hotel, $chef)->post(route('hotel.restaurant.orders.status', $order), ['status' => 'delivered'])->assertSessionHasErrors('status');
    asStaffOf($this->hotel, $chef)->post(route('hotel.restaurant.orders.status', $order), ['status' => 'ready']);
    asStaffOf($this->hotel, $chef)->post(route('hotel.restaurant.orders.status', $order), ['status' => 'delivered']);

    expect(($this->in)(fn () => $order->fresh()->status))->toBe(OrderStatus::Delivered)
        ->and(AuditLog::query()->whereIn('event', ['orders.placed', 'orders.accepted', 'orders.ready', 'orders.delivered'])->count())->toBe(4);
});

it('takes phone and counter orders on the board', function (): void {
    $stay = checkedIn($this->hotel, $this->category);
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->post(route('hotel.restaurant.orders.store'), [
        'outlet_id' => $this->outlet->id, 'items' => [['menu_item_id' => $this->flag->id, 'quantity' => 3]],
        'delivery' => 'outlet', 'location_note' => 'Piscine', 'payment' => 'room_charge', 'booking_id' => $stay->id, 'source' => 'counter',
    ])->assertSessionHasNoErrors();

    $order = ($this->in)(fn () => Order::query()->sole());
    expect($order->total_minor)->toBe(3000)->and($order->location_note)->toBe('Piscine')->and($order->source)->toBe(OrderSource::Counter);
});

it('keeps the board to people who handle orders and each hotel to its own orders', function (): void {
    $order = ($this->place)();

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))->get(route('hotel.restaurant.orders'))->assertForbidden();
    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->post(route('hotel.restaurant.orders.status', $order), ['status' => 'cancelled'])->assertNotFound();
});

it('lets reception add extra numbers to a stay', function (): void {
    $stay = checkedIn($this->hotel, $this->category);
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->post(route('hotel.bookings.phones.store', $stay), ['phone' => '+225 05 11 22 33 44'])->assertSessionHasNoErrors();
    expect(($this->in)(fn () => StayPhone::query()->sole()->phone))->toBe('+2250511223344')
        ->and(AuditLog::query()->where('event', 'bookings.stay_phone_added')->exists())->toBeTrue();

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::RestaurantBar))->post(route('hotel.bookings.phones.store', $stay), ['phone' => '+2250511223345'])->assertForbidden();
});
