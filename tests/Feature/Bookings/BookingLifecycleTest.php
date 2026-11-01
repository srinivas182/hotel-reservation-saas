<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Availability\Models\InventoryDay;
use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Exceptions\BookingActionNotAllowed;
use App\Domains\Bookings\Exceptions\BookingNotPossible;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Bookings\RoomAssignments;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomClosure;
use App\Domains\Rooms\RoomStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-12 09:00:00');
    $this->hotel = Hotel::factory()->create(['name' => 'First Class Lodge', 'currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $this->exe = roomCategory($this->hotel, 'EXE', 3);
    $this->std = roomCategory($this->hotel, 'STD', 2);
    rateFor($this->hotel, $this->exe);
    rateFor($this->hotel, $this->std, ['base_minor' => 20000]);
    $this->context = app(TenantContext::class);
    $this->life = fn (): BookingLifecycle => app(BookingLifecycle::class);
    $this->in = fn (callable $callback) => $this->context->run($this->hotel, $callback);
});

function stay(string $category, string $in, string $out, int $rooms = 1, int $adults = 2): StayRequest
{
    return new StayRequest($category, CarbonImmutable::parse($in), CarbonImmutable::parse($out), adults: $adults, rooms: $rooms);
}

function roomNumbered(Hotel $hotel, string $categoryId, int $index = 0): Room
{
    return app(TenantContext::class)->run($hotel, fn (): Room => Room::query()->where('room_category_id', $categoryId)->orderBy('number')->get()[$index]);
}

it('confirms a tentative booking and books its nights', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-20', '2026-11-22', status: BookingStatus::Tentative);

    ($this->in)(fn () => ($this->life)()->confirm($booking));

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->tentative_until)->toBeNull()
        ->and(($this->in)(fn () => (int) InventoryDay::query()->sum('booked')))->toBe(2)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-20', '2026-11-21'))->toBe(['2026-11-20' => 2, '2026-11-21' => 2]);
});

it('confirms a lapsed tentative booking only if its rooms are still free', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-20', '2026-11-21', rooms: 3, adults: 3, status: BookingStatus::Tentative);
    $this->travel(25)->hours();
    book($this->hotel, $this->exe, '2026-11-20', '2026-11-21');

    expect(fn () => ($this->in)(fn () => ($this->life)()->confirm($booking)))->toThrow(BookingActionNotAllowed::class);
});

it('cancels tentative bookings that were not confirmed in time', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-20', '2026-11-21', rooms: 3, adults: 3, status: BookingStatus::Tentative);
    $this->travel(25)->hours();

    $this->artisan('bookings:expire-tentative')->assertSuccessful();

    $booking = ($this->in)(fn () => $booking->fresh());
    expect($booking->status)->toBe(BookingStatus::Cancelled)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-20', '2026-11-20'))->toBe(['2026-11-20' => 3])
        ->and(AuditLog::query()->where('event', 'bookings.expired')->exists())->toBeTrue();
});

it('changes the dates, freeing the old nights and booking the new ones', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-20', '2026-11-22');

    ($this->in)(fn () => ($this->life)()->modify($booking, stay($this->exe->id, '2026-11-25', '2026-11-28')));

    expect($booking->check_in->toDateString())->toBe('2026-11-25')
        ->and($booking->total_minor)->toBe(75000)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-20', '2026-11-21'))->toBe(['2026-11-20' => 3, '2026-11-21' => 3])
        ->and(availableNights($this->hotel, $this->exe, '2026-11-25', '2026-11-27'))->toBe(['2026-11-25' => 2, '2026-11-26' => 2, '2026-11-27' => 2]);
});

it('lets a booking grow into its own nights but changes nothing when the new rooms are taken', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-20', '2026-11-22', rooms: 2, adults: 2);
    book($this->hotel, $this->exe, '2026-11-22', '2026-11-23', rooms: 3, adults: 3);

    // 3 rooms on the same nights works: the booking's own 2 rooms count as free.
    ($this->in)(fn () => ($this->life)()->modify($booking, stay($this->exe->id, '2026-11-20', '2026-11-22', rooms: 3, adults: 3)));
    expect($booking->rooms)->toBe(3);

    // Extending into a full night fails and leaves everything as it was.
    expect(fn () => ($this->in)(fn () => ($this->life)()->modify($booking, stay($this->exe->id, '2026-11-20', '2026-11-23', rooms: 3, adults: 3))))
        ->toThrow(BookingNotPossible::class);

    $booking = ($this->in)(fn () => $booking->fresh());
    expect($booking->check_out->toDateString())->toBe('2026-11-22')
        ->and(availableNights($this->hotel, $this->exe, '2026-11-20', '2026-11-22'))->toBe(['2026-11-20' => 0, '2026-11-21' => 0, '2026-11-22' => 0]);
});

it('keeps a changed price only when it is given again', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-20', '2026-11-22', override: Money::of(40000, 'XOF'), reason: 'Regular guest');

    ($this->in)(fn () => ($this->life)()->modify($booking, stay($this->exe->id, '2026-11-20', '2026-11-23')));
    expect($booking->price_overridden)->toBeFalse()->and($booking->total_minor)->toBe(75000);

    ($this->in)(fn () => ($this->life)()->modify($booking, stay($this->exe->id, '2026-11-20', '2026-11-23'), overrideTotal: Money::of(60000, 'XOF'), overrideReason: 'Regular guest'));
    expect($booking->price_overridden)->toBeTrue()->and($booking->total_minor)->toBe(60000);
});

it('removes room numbers that no longer fit when the category or room count changes', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-20', '2026-11-22', rooms: 2);
    ($this->in)(fn () => ($this->life)()->assignRoom($booking, 1, roomNumbered($this->hotel, $this->exe->id, 0)));
    ($this->in)(fn () => ($this->life)()->assignRoom($booking, 2, roomNumbered($this->hotel, $this->exe->id, 1)));

    ($this->in)(fn () => ($this->life)()->modify($booking, stay($this->exe->id, '2026-11-20', '2026-11-22', rooms: 1)));
    expect(($this->in)(fn () => BookingRoom::query()->where('booking_id', $booking->id)->count()))->toBe(1)
        ->and(($this->in)(fn () => BookingRoom::query()->where('booking_id', $booking->id)->value('room_id')))->not->toBeNull();

    ($this->in)(fn () => ($this->life)()->modify($booking, stay($this->std->id, '2026-11-20', '2026-11-22')));
    expect(($this->in)(fn () => BookingRoom::query()->where('booking_id', $booking->id)->value('room_id')))->toBeNull()
        ->and($booking->total_minor)->toBe(40000);
});

it('cancels with a reason and frees the nights', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-20', '2026-11-22');

    expect(fn () => ($this->in)(fn () => ($this->life)()->cancel($booking, '  ')))->toThrow(BookingActionNotAllowed::class);

    ($this->in)(fn () => ($this->life)()->cancel($booking, 'Guest changed plans'));

    expect($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancel_reason)->toBe('Guest changed plans')
        ->and(availableNights($this->hotel, $this->exe, '2026-11-20', '2026-11-21'))->toBe(['2026-11-20' => 3, '2026-11-21' => 3]);
});

it('marks no-shows only from the arrival date and frees all nights', function (): void {
    $future = book($this->hotel, $this->exe, '2026-11-20', '2026-11-22');
    expect(fn () => ($this->in)(fn () => ($this->life)()->noShow($future)))->toThrow(BookingActionNotAllowed::class);

    $today = book($this->hotel, $this->exe, '2026-11-12', '2026-11-14');
    ($this->in)(fn () => ($this->life)()->noShow($today));

    expect($today->status)->toBe(BookingStatus::NoShow)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-13'))->toBe(['2026-11-12' => 3, '2026-11-13' => 3]);
});

it('checks in only on the arrival date and with a room number for every room', function (): void {
    $future = book($this->hotel, $this->exe, '2026-11-20', '2026-11-22');
    expect(fn () => ($this->in)(fn () => ($this->life)()->checkIn($future)))->toThrow(BookingActionNotAllowed::class);

    $booking = book($this->hotel, $this->exe, '2026-11-12', '2026-11-14', rooms: 2);
    expect(fn () => ($this->in)(fn () => ($this->life)()->checkIn($booking, [1 => roomNumbered($this->hotel, $this->exe->id, 0)->id])))
        ->toThrow(BookingActionNotAllowed::class);

    // Nothing was saved by the failed attempt.
    expect(($this->in)(fn () => BookingRoom::query()->where('booking_id', $booking->id)->whereNotNull('room_id')->count()))->toBe(0);

    ($this->in)(fn () => ($this->life)()->checkIn($booking, [
        1 => roomNumbered($this->hotel, $this->exe->id, 0)->id,
        2 => roomNumbered($this->hotel, $this->exe->id, 1)->id,
    ]));

    expect($booking->status)->toBe(BookingStatus::CheckedIn)->and($booking->checked_in_at)->not->toBeNull();
});

it('never gives a room that is taken, closed or out of service, and suggests a valid one', function (): void {
    $first = book($this->hotel, $this->exe, '2026-11-12', '2026-11-15');
    $second = book($this->hotel, $this->exe, '2026-11-14', '2026-11-16', phone: '+2250700000002');
    [$a, $b, $c] = [roomNumbered($this->hotel, $this->exe->id, 0), roomNumbered($this->hotel, $this->exe->id, 1), roomNumbered($this->hotel, $this->exe->id, 2)];

    ($this->in)(fn () => ($this->life)()->assignRoom($first, 1, $a));
    expect(fn () => ($this->in)(fn () => ($this->life)()->assignRoom($second, 1, $a)))->toThrow(BookingActionNotAllowed::class);

    ($this->in)(fn () => RoomClosure::query()->create(['room_id' => $b->id, 'from_date' => '2026-11-15', 'to_date' => '2026-11-15', 'reason' => 'Paint']));
    expect(fn () => ($this->in)(fn () => ($this->life)()->assignRoom($second, 1, $b)))->toThrow(BookingActionNotAllowed::class);

    ($this->in)(fn () => $c->update(['status' => RoomStatus::OutOfOrder]));
    expect(fn () => ($this->in)(fn () => ($this->life)()->assignRoom($second, 1, $c)))->toThrow(BookingActionNotAllowed::class);

    expect(($this->in)(fn () => app(RoomAssignments::class)->suggest($second)))->toBeNull();

    ($this->in)(fn () => $c->update(['status' => RoomStatus::InService]));
    expect(($this->in)(fn () => app(RoomAssignments::class)->suggest($second))?->id)->toBe($c->id);
});

it('records a room move during a stay', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-12', '2026-11-14');
    ($this->in)(fn () => ($this->life)()->checkIn($booking, [1 => roomNumbered($this->hotel, $this->exe->id, 0)->id]));

    ($this->in)(fn () => ($this->life)()->assignRoom($booking, 1, roomNumbered($this->hotel, $this->exe->id, 1)));

    expect(AuditLog::query()->where('event', 'bookings.room_moved')->exists())->toBeTrue();
});

it('checks out early and puts the unused nights back on sale', function (): void {
    $this->travelTo('2026-11-11 15:00:00');
    $booking = book($this->hotel, $this->exe, '2026-11-11', '2026-11-15');
    ($this->in)(fn () => ($this->life)()->checkIn($booking, [1 => roomNumbered($this->hotel, $this->exe->id, 0)->id]));

    $this->travelTo('2026-11-13 08:00:00');
    ($this->in)(fn () => ($this->life)()->checkOut($booking));

    expect($booking->status)->toBe(BookingStatus::CheckedOut)
        ->and($booking->check_out->toDateString())->toBe('2026-11-13')
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-14'))->toBe(['2026-11-12' => 2, '2026-11-13' => 3, '2026-11-14' => 3])
        ->and(AuditLog::query()->where('event', 'bookings.early_departure')->sole()->properties['nights_freed'])->toBe(2);
});

it('keeps the first night when a guest leaves on the arrival day', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-12', '2026-11-14');
    ($this->in)(fn () => ($this->life)()->checkIn($booking, [1 => roomNumbered($this->hotel, $this->exe->id, 0)->id]));
    ($this->in)(fn () => ($this->life)()->checkOut($booking));

    expect($booking->check_out->toDateString())->toBe('2026-11-13')
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-13'))->toBe(['2026-11-12' => 2, '2026-11-13' => 3]);
});

it('refuses actions that do not fit the booking status', function (): void {
    $confirmed = book($this->hotel, $this->exe, '2026-11-12', '2026-11-14');

    expect(fn () => ($this->in)(fn () => ($this->life)()->confirm($confirmed)))->toThrow(BookingActionNotAllowed::class)
        ->and(fn () => ($this->in)(fn () => ($this->life)()->checkOut($confirmed)))->toThrow(BookingActionNotAllowed::class);

    ($this->in)(fn () => ($this->life)()->cancel($confirmed, 'Test'));

    expect(fn () => ($this->in)(fn () => ($this->life)()->modify($confirmed, stay($this->exe->id, '2026-11-12', '2026-11-13'))))->toThrow(BookingActionNotAllowed::class)
        ->and(fn () => ($this->in)(fn () => ($this->life)()->cancel($confirmed, 'Again')))->toThrow(BookingActionNotAllowed::class)
        ->and(fn () => ($this->in)(fn () => ($this->life)()->checkIn($confirmed)))->toThrow(BookingActionNotAllowed::class);
});

it('keeps availability consistent with a rebuild after any mix of actions', function (): void {
    $a = book($this->hotel, $this->exe, '2026-11-12', '2026-11-16', phone: '+2250700000010');
    $b = book($this->hotel, $this->exe, '2026-11-13', '2026-11-15', phone: '+2250700000011');
    $c = book($this->hotel, $this->exe, '2026-11-14', '2026-11-17', phone: '+2250700000012');
    $d = book($this->hotel, $this->std, '2026-11-12', '2026-11-13', phone: '+2250700000013');

    ($this->in)(function () use ($a, $b, $c, $d): void {
        $life = app(BookingLifecycle::class);
        $life->checkIn($a, [1 => roomNumbered($this->hotel, $this->exe->id, 0)->id]);
        $life->cancel($b, 'Plans changed');
        $life->modify($c, stay($this->exe->id, '2026-11-15', '2026-11-18'));
        $life->noShow($d);
    });
    $this->travelTo('2026-11-14 08:00:00');
    ($this->in)(fn () => app(BookingLifecycle::class)->checkOut($a));

    $before = ($this->in)(fn () => InventoryDay::query()->orderBy('room_category_id')->orderBy('date')->get(['room_category_id', 'date', 'booked'])->map(fn ($d) => [$d->room_category_id, $d->date->toDateString(), $d->booked])->all());
    $this->artisan('inventory:rebuild')->assertSuccessful();
    $after = ($this->in)(fn () => InventoryDay::query()->orderBy('room_category_id')->orderBy('date')->get(['room_category_id', 'date', 'booked'])->map(fn ($d) => [$d->room_category_id, $d->date->toDateString(), $d->booked])->all());

    expect($after)->toBe($before);
});

it('runs the lifecycle through the booking page with the right permissions', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-12', '2026-11-14');
    $reception = staffMember($this->hotel, SystemRole::Reception);
    $readOnly = staffMember($this->hotel, SystemRole::ReadOnly);

    asStaffOf($this->hotel, $readOnly)->post(route('hotel.bookings.cancel', $booking), ['reason' => 'x'])->assertForbidden();
    asStaffOf($this->hotel, $readOnly)->post(route('hotel.bookings.check-in', $booking))->assertForbidden();

    asStaffOf($this->hotel, $reception)
        ->get(route('hotel.bookings.show', $booking))
        ->assertInertia(fn ($page) => $page
            ->where('actions.check_in', true)
            ->where('actions.check_out', false)
            ->where('suggestedRoom', roomNumbered($this->hotel, $this->exe->id, 0)->id)
            ->has('eligibleRooms', 3));

    asStaffOf($this->hotel, $reception)->post(route('hotel.bookings.check-in', $booking), ['rooms' => [1 => roomNumbered($this->hotel, $this->exe->id, 0)->id]])->assertRedirect();
    expect(($this->in)(fn () => $booking->fresh()->status))->toBe(BookingStatus::CheckedIn);

    // A refused action comes back as a message, not an error page.
    asStaffOf($this->hotel, $reception)->post(route('hotel.bookings.check-in', $booking))->assertRedirect()->assertSessionHas('inertia.flash_data');

    asStaffOf($this->hotel, $reception)->put(route('hotel.bookings.update', ($this->in)(fn () => book($this->hotel, $this->exe, '2026-11-20', '2026-11-21', phone: '+2250700000099'))), [
        'room_category_id' => $this->exe->id, 'check_in' => '2026-11-20', 'check_out' => '2026-11-22', 'adults' => 2, 'children' => '',
        'rooms' => 1, 'breakfast' => false, 'extra_bed' => false, 'override_total' => '1000', 'override_reason' => 'x',
    ])->assertForbidden();
});
