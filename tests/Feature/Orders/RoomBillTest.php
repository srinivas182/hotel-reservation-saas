<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Folio\Folio;
use App\Domains\Folio\FolioLine;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Orders\Exceptions\OrderNotPossible;
use App\Domains\Orders\NewOrder;
use App\Domains\Orders\OrderDelivery;
use App\Domains\Orders\OrderPayment;
use App\Domains\Orders\OrderService;
use App\Domains\Orders\OrderSource;
use App\Domains\Orders\OrderStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-13 20:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $this->category = roomCategory($this->hotel, 'EXE', 3);
    rateFor($this->hotel, $this->category);
    [$this->outlet, $this->poulet, $this->flag] = roomService($this->hotel);
    $this->stay = checkedIn($this->hotel, $this->category);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
    $this->statement = fn () => ($this->in)(fn () => app(Folio::class)->statement($this->stay->fresh()));
    $this->order = fn (int $poulets = 1) => ($this->in)(fn () => app(OrderService::class)->place(new NewOrder(
        outletId: $this->outlet->id, items: [['menu_item_id' => $this->poulet->id, 'quantity' => $poulets]], source: OrderSource::Phone,
        delivery: OrderDelivery::Room, payment: OrderPayment::RoomCharge, bookingId: $this->stay->id, roomNumber: 'EXE1',
    )));
});

it('builds the bill from the nights, extra charges, payments and corrections', function (): void {
    ($this->in)(fn () => TaxRate::query()->create(['name' => ['fr' => 'TVA'], 'type' => 'percentage', 'percentage_bps' => 1000, 'inclusive' => false, 'applies_to' => ['food_beverage'], 'active' => true]));
    $nights = $this->stay->total_minor;

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.charges.store', $this->stay), ['description' => 'Minibar', 'quantity' => 2, 'unit_price' => 1000, 'tax_scope' => 'food_beverage'])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.charges.store', $this->stay), ['description' => 'Blanchisserie', 'quantity' => 1, 'unit_price' => 3000])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.payments.store', $this->stay), ['amount' => 10000, 'method' => 'orange_money', 'reference' => 'OM123'])->assertSessionHasNoErrors();

    $s = ($this->statement)();
    expect(array_column($s['lines'], 'amount_minor'))->toBe([$nights, 2200, 3000, 10000])
        ->and($s['balance_minor'])->toBe($nights + 5200 - 10000);

    $laundry = ($this->in)(fn () => FolioLine::query()->where('description', 'Blanchisserie')->sole());
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.folio-lines.correct', $laundry), ['reason' => 'Facturé par erreur'])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.folio-lines.correct', $laundry), ['reason' => 'Encore'])->assertSessionHasErrors('reason');

    $s = ($this->statement)();
    expect($s['balance_minor'])->toBe($nights + 2200 - 10000)
        ->and(collect($s['lines'])->firstWhere('id', $laundry->id)['corrected'])->toBeTrue()
        ->and(AuditLog::query()->whereIn('event', ['folio.charge', 'folio.payment', 'folio.correction'])->count())->toBe(4);

    // Lines are never edited or deleted.
    expect(($this->in)(fn () => $laundry->update(['amount_minor' => 1])))->toBeFalse()
        ->and(($this->in)(fn () => $laundry->delete()))->toBeFalse();
});

it('follows the booking when its dates change', function (): void {
    $before = ($this->statement)()['lines'][0]['amount_minor'];
    ($this->in)(fn () => $this->stay->update(['total_minor' => $before * 2]));

    expect(($this->statement)()['lines'][0]['amount_minor'])->toBe($before * 2);
});

it('puts delivered room-charge orders on the bill once', function (): void {
    $order = ($this->order)(2);

    expect(($this->statement)()['lines'])->toHaveCount(1);

    ($this->in)(function () use ($order): void {
        $service = app(OrderService::class);
        $service->move($order, OrderStatus::Accepted, null, 20);
        $service->move($order, OrderStatus::Ready);
        $service->move($order, OrderStatus::Delivered);
        app(Folio::class)->postOrder($order->fresh());
    });

    $lines = ($this->statement)()['lines'];
    expect($lines)->toHaveCount(2)->and($lines[1]['amount_minor'])->toBe(10000)->and($lines[1]['reference'])->toBe($order->reference);
});

it('keeps room charges within the hotel\'s limits', function (): void {
    ($this->in)(fn () => RestaurantSettings::current()->update(['room_charge_order_limit_minor' => 8000, 'room_charge_stay_limit_minor' => 12000]));

    expect(fn () => ($this->order)(2))->toThrow(fn (OrderNotPossible $e) => expect($e->reason)->toBe('room_charge_limit'));

    ($this->order)(1); // 5 000
    ($this->order)(1); // 10 000 in total
    expect(fn () => ($this->order)(1))->toThrow(fn (OrderNotPossible $e) => expect($e->reason)->toBe('room_charge_limit'));

    ($this->in)(fn () => RestaurantSettings::current()->update(['room_charge_enabled' => false, 'room_charge_order_limit_minor' => null, 'room_charge_stay_limit_minor' => null]));
    expect(fn () => ($this->order)(1))->toThrow(fn (OrderNotPossible $e) => expect($e->reason)->toBe('room_charge_off'));
});

it('asks for the payment or a reason before checking out with a balance', function (): void {
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.check-out', $this->stay))->assertSessionHasErrors('balance');
    expect(($this->in)(fn () => $this->stay->fresh()->status))->toBe(BookingStatus::CheckedIn);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.check-out', $this->stay), ['balance_reason' => 'Payé par virement de la société, reçu demain']);
    expect(($this->in)(fn () => $this->stay->fresh()->status))->toBe(BookingStatus::CheckedOut)
        ->and(AuditLog::query()->where('event', 'bookings.checked_out_with_balance')->sole()->properties['balance_minor'])->toBe($this->stay->total_minor);
});

it('checks out without a question when the bill is settled', function (): void {
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.payments.store', $this->stay), ['amount' => $this->stay->total_minor, 'method' => 'cash']);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.check-out', $this->stay))->assertSessionHasNoErrors();
    expect(($this->in)(fn () => $this->stay->fresh()->status))->toBe(BookingStatus::CheckedOut);
});

it('prints a statement of account and keeps bills to the right people and hotel', function (): void {
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.charges.store', $this->stay), ['description' => 'Minibar', 'quantity' => 1, 'unit_price' => 1500]);

    asStaffOf($this->hotel, $this->reception)->get(route('hotel.bookings.statement', $this->stay))
        ->assertOk()->assertSee('Minibar')->assertSee($this->stay->reference);

    $readOnly = staffMember($this->hotel, SystemRole::ReadOnly);
    asStaffOf($this->hotel, $readOnly)->get(route('hotel.bookings.statement', $this->stay))->assertOk();
    asStaffOf($this->hotel, $readOnly)->post(route('hotel.bookings.payments.store', $this->stay), ['amount' => 100, 'method' => 'cash'])->assertForbidden();
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::RestaurantBar))->get(route('hotel.bookings.statement', $this->stay))->assertForbidden();

    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->get(route('hotel.bookings.statement', $this->stay))->assertNotFound();
});

it('lets managers set room charge limits and payment methods', function (): void {
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))->put(route('hotel.restaurant.room-charges'), [
        'room_charge_enabled' => true, 'order_limit' => 25000, 'stay_limit' => 100000, 'accept_outside_orders' => false, 'payment_methods' => ['cash', 'wave'],
    ])->assertSessionHasNoErrors();

    $settings = ($this->in)(fn () => RestaurantSettings::current());
    expect($settings->room_charge_order_limit_minor)->toBe(25000)->and($settings->methods())->toBe(['cash', 'wave'])->and($settings->accept_outside_orders)->toBeFalse();

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.payments.store', $this->stay), ['amount' => 100, 'method' => 'orange_money'])->assertSessionHasErrors('method');
});
