<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\BookingRequests\BookingChangeRequests;
use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Exceptions\BookingActionNotAllowed;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Bookings\StayChanges;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\Room;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

/*
 * 0.40.0: extend the stay or leave early, for a guest who is checked in.
 */

beforeEach(function (): void {
    $this->travelTo('2026-11-12 09:00:00');
    $this->hotel = Hotel::factory()->create(['name' => 'First Class Lodge', 'currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $this->exe = roomCategory($this->hotel, 'EXE', 3);
    rateFor($this->hotel, $this->exe);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->rooms = ($this->in)(fn () => Room::query()->where('room_category_id', $this->exe->id)->orderBy('number')->get());
    $this->stays = fn (): StayChanges => app(StayChanges::class);
    $this->checkedIn = function (string $in = '2026-11-12', string $out = '2026-11-14', int $room = 0, string $phone = '+2250700000001'): Booking {
        $booking = book($this->hotel, $this->exe, $in, $out, phone: $phone);
        ($this->in)(fn () => app(BookingLifecycle::class)->checkIn($booking, [1 => $this->rooms[$room]->id]));

        return $booking;
    };
});

it('extends the stay: prices only the extra nights, books them and keeps the room', function (): void {
    $booking = ($this->checkedIn)();
    $twoNights = $booking->total_minor;

    $preview = ($this->in)(fn () => ($this->stays)()->preview($booking, CarbonImmutable::parse('2026-11-16')));
    expect($preview['kind'])->toBe('extend')
        ->and($preview['possible'])->toBeTrue()
        ->and($preview['nights_changed'])->toBe(2)
        ->and($preview['difference_minor'])->toBe($twoNights)
        ->and($preview['room_conflicts'])->toBe([]);

    ($this->in)(fn () => ($this->stays)()->change($booking, CarbonImmutable::parse('2026-11-16')));

    $booking = ($this->in)(fn () => $booking->fresh());
    expect($booking->check_out->toDateString())->toBe('2026-11-16')
        ->and($booking->total_minor)->toBe(2 * $twoNights)
        ->and(count($booking->price_snapshot['nights']))->toBe(4)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-14', '2026-11-16'))->toBe(['2026-11-14' => 2, '2026-11-15' => 2, '2026-11-16' => 3])
        ->and(($this->in)(fn () => BookingRoom::query()->where('booking_id', $booking->id)->value('room_id')))->toBe($this->rooms[0]->id)
        ->and(AuditLog::query()->where('event', 'bookings.extended')->exists())->toBeTrue();
});

it('moves the guest when their room is taken on the extra nights', function (): void {
    $booking = ($this->checkedIn)();
    $next = book($this->hotel, $this->exe, '2026-11-14', '2026-11-15', phone: '+2250700000002');
    ($this->in)(fn () => app(BookingLifecycle::class)->assignRoom($next, 1, $this->rooms[0]));

    $preview = ($this->in)(fn () => ($this->stays)()->preview($booking, CarbonImmutable::parse('2026-11-15')));
    expect($preview['room_conflicts'])->toHaveCount(1)
        ->and(array_column($preview['room_conflicts'][0]['free'], 'id'))->not->toContain($this->rooms[0]->id);

    expect(fn () => ($this->in)(fn () => ($this->stays)()->change($booking, CarbonImmutable::parse('2026-11-15'))))
        ->toThrow(BookingActionNotAllowed::class);
    // Nothing changed.
    expect(($this->in)(fn () => $booking->fresh()->check_out->toDateString()))->toBe('2026-11-14');

    ($this->in)(fn () => ($this->stays)()->change($booking, CarbonImmutable::parse('2026-11-15'), roomsByPosition: [1 => $this->rooms[1]->id]));

    expect(($this->in)(fn () => BookingRoom::query()->where('booking_id', $booking->id)->value('room_id')))->toBe($this->rooms[1]->id)
        ->and(($this->in)(fn () => $booking->fresh()->check_out->toDateString()))->toBe('2026-11-15');
});

it('refuses an extension when no room is free', function (): void {
    $booking = ($this->checkedIn)();
    book($this->hotel, $this->exe, '2026-11-14', '2026-11-15', rooms: 2, phone: '+2250700000003');
    book($this->hotel, $this->exe, '2026-11-14', '2026-11-15', phone: '+2250700000004');

    expect(($this->in)(fn () => ($this->stays)()->preview($booking, CarbonImmutable::parse('2026-11-15')))['possible'])->toBeFalse()
        ->and(fn () => ($this->in)(fn () => ($this->stays)()->change($booking, CarbonImmutable::parse('2026-11-15'))))->toThrow(BookingActionNotAllowed::class);
});

it('leaving early takes the nights not stayed off the bill by default', function (): void {
    $booking = ($this->checkedIn)('2026-11-12', '2026-11-16');
    $fourNights = $booking->total_minor;

    ($this->in)(fn () => ($this->stays)()->change($booking, CarbonImmutable::parse('2026-11-14')));

    $booking = ($this->in)(fn () => $booking->fresh());
    expect($booking->check_out->toDateString())->toBe('2026-11-14')
        ->and($booking->total_minor)->toBe(intdiv($fourNights, 2))
        ->and($booking->status)->toBe(BookingStatus::CheckedIn)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-13', '2026-11-15'))->toBe(['2026-11-13' => 2, '2026-11-14' => 3, '2026-11-15' => 3]);
});

it('still charges one night not stayed when the hotel chose so', function (): void {
    ($this->in)(fn () => HotelSettings::current()->update(['early_departure_charge' => StayChanges::CHARGE_ONE_NIGHT]));
    $booking = ($this->checkedIn)('2026-11-12', '2026-11-16');
    $fourNights = $booking->total_minor;

    $preview = ($this->in)(fn () => ($this->stays)()->preview($booking, CarbonImmutable::parse('2026-11-14')));
    expect($preview['fee_nights'])->toBe(1)
        ->and($preview['new_total_minor'])->toBe(intdiv($fourNights * 3, 4));
});

it('reprices an early check-out and checks the balance against the lower total', function (): void {
    $this->travelTo('2026-11-11 15:00:00');
    $booking = ($this->checkedIn)('2026-11-11', '2026-11-15');
    $fourNights = $booking->total_minor;

    $this->travelTo('2026-11-13 08:00:00');
    expect(($this->in)(fn () => app(BookingLifecycle::class)->totalAfterCheckOut($booking)))->toBe(intdiv($fourNights, 2));

    ($this->in)(fn () => app(BookingLifecycle::class)->checkOut($booking));

    expect($booking->total_minor)->toBe(intdiv($fourNights, 2))
        ->and(AuditLog::query()->where('event', 'bookings.early_departure')->sole()->properties['new_total_minor'])->toBe(intdiv($fourNights, 2));
});

it('refuses a departure before today, or for a guest not checked in', function (): void {
    $booking = ($this->checkedIn)('2026-11-12', '2026-11-16');
    $confirmed = book($this->hotel, $this->exe, '2026-11-20', '2026-11-22', phone: '+2250700000005');

    expect(fn () => ($this->in)(fn () => ($this->stays)()->change($booking, CarbonImmutable::parse('2026-11-12'))))->toThrow(BookingActionNotAllowed::class)
        ->and(fn () => ($this->in)(fn () => ($this->stays)()->change($confirmed, CarbonImmutable::parse('2026-11-23'))))->toThrow(BookingActionNotAllowed::class);
});

it('lets reception change the departure from the booking page', function (): void {
    $booking = ($this->checkedIn)();
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)
        ->get(route('hotel.bookings.show', ['booking' => $booking, 'new_check_out' => '2026-11-15']))
        ->assertInertia(fn ($page) => $page->where('actions.change_departure', true)
            ->reloadOnly('departure', fn ($reload) => $reload->where('departure.kind', 'extend')->where('departure.nights_changed', 1)));

    asStaffOf($this->hotel, $reception)
        ->put(route('hotel.bookings.departure', $booking), ['check_out' => '2026-11-15'])
        ->assertRedirect();

    expect(($this->in)(fn () => $booking->fresh()->check_out->toDateString()))->toBe('2026-11-15');

    asStaffOf($this->hotel, $reception)
        ->put(route('hotel.bookings.departure', $booking), ['check_out' => '2026-11-11'])
        ->assertSessionHasErrors('check_out');
});

it('applies a guest WhatsApp request to stay longer', function (): void {
    $booking = ($this->checkedIn)();
    $manager = staffMember($this->hotel, SystemRole::Manager);

    ($this->in)(function () use ($booking, $manager): void {
        $stay = new StayRequest($booking->room_category_id, $booking->check_in, CarbonImmutable::parse('2026-11-15'), adults: $booking->adults);
        $request = app(BookingChangeRequests::class)->requestChange($booking, null, $stay, Brick\Money\Money::ofMinor($booking->total_minor, 'XOF'), 'stay longer');
        app(BookingChangeRequests::class)->apply($request, $manager);
    });

    expect(($this->in)(fn () => $booking->fresh()->check_out->toDateString()))->toBe('2026-11-15')
        ->and(($this->in)(fn () => $booking->fresh()->status))->toBe(BookingStatus::CheckedIn);
});
