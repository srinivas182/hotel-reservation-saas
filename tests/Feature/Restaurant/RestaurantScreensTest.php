<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\Models\ServicePeriod;
use App\Domains\Restaurant\Models\TableBooking;
use App\Domains\Restaurant\TableBookingStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-13 12:00:00');
    $this->hotel = Hotel::factory()->create(['timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $this->outlet = restaurant($this->hotel);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->staff = staffMember($this->hotel, SystemRole::RestaurantBar);
});

it('takes a phone booking and shows tonight\'s bookings by service', function (): void {
    asStaffOf($this->hotel, $this->staff)->post(route('hotel.restaurant.bookings.store'), [
        'outlet_id' => $this->outlet->id, 'date' => '2026-11-13', 'time' => '20:00', 'covers' => 4,
        'first_name' => 'Kofi', 'last_name' => 'Mensah', 'phone' => '+225 07 11 22 33 44', 'source' => 'phone', 'notes' => 'Anniversaire, allergie aux arachides',
    ])->assertSessionHasNoErrors();

    $booking = ($this->in)(fn () => TableBooking::query()->sole());
    expect($booking->status)->toBe(TableBookingStatus::Confirmed)
        ->and($booking->reference)->toBe('T-26-0001')
        ->and(DB::table('table_bookings')->value('notes'))->not->toContain('arachides')
        ->and(AuditLog::query()->where('event', 'table_bookings.created')->exists())->toBeTrue();

    asStaffOf($this->hotel, $this->staff)->get(route('hotel.restaurant'))
        ->assertInertia(fn ($page) => $page->where('periods.0.name', 'Dîner')->where('periods.0.covers', 4)->where('periods.0.bookings.0.guest', 'Kofi Mensah')->where('occupancy.20:00', 4));

    asStaffOf($this->hotel, $this->staff)->get(route('hotel.restaurant', ['covers' => 18, 'date' => '2026-11-13']))
        ->assertInertia(fn ($page) => $page->reloadOnly('slots', fn ($reload) => $reload->where('slots.2.time', '20:00')->where('slots.2.available', false)));
});

it('refuses a time that is full', function (): void {
    bookTable($this->hotel, $this->outlet, '2026-11-13', '20:00', 20);

    asStaffOf($this->hotel, $this->staff)->post(route('hotel.restaurant.bookings.store'), [
        'outlet_id' => $this->outlet->id, 'date' => '2026-11-13', 'time' => '20:30', 'covers' => 2, 'first_name' => 'Kofi', 'source' => 'walk_in',
    ])->assertSessionHasErrors('time');
});

it('moves bookings through arrival, finish and no-show, audited, and only on the day', function (): void {
    $tonight = bookTable($this->hotel, $this->outlet, '2026-11-13', '20:00', 4);
    $tomorrow = bookTable($this->hotel, $this->outlet, '2026-11-14', '20:00', 2, '+2250700000002');

    asStaffOf($this->hotel, $this->staff)->post(route('hotel.restaurant.bookings.status', $tonight), ['status' => 'arrived'])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->staff)->post(route('hotel.restaurant.bookings.status', $tonight), ['status' => 'finished'])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->staff)->post(route('hotel.restaurant.bookings.status', $tonight), ['status' => 'arrived'])->assertSessionHasErrors('status');
    asStaffOf($this->hotel, $this->staff)->post(route('hotel.restaurant.bookings.status', $tomorrow), ['status' => 'no_show'])->assertSessionHasErrors('status');

    expect(($this->in)(fn () => $tonight->fresh()->status))->toBe(TableBookingStatus::Finished)
        ->and(AuditLog::query()->where('event', 'table_bookings.finished')->exists())->toBeTrue();
});

it('changes a booking only to a time that fits', function (): void {
    $booking = bookTable($this->hotel, $this->outlet, '2026-11-13', '19:00', 4);
    bookTable($this->hotel, $this->outlet, '2026-11-13', '21:00', 18, '+2250700000002');

    asStaffOf($this->hotel, $this->staff)->put(route('hotel.restaurant.bookings.update', $booking), ['date' => '2026-11-13', 'time' => '21:00', 'covers' => 4])->assertSessionHasErrors('time');
    asStaffOf($this->hotel, $this->staff)->put(route('hotel.restaurant.bookings.update', $booking), ['date' => '2026-11-13', 'time' => '19:30', 'covers' => 6])->assertSessionHasNoErrors();

    expect(($this->in)(fn () => $booking->fresh()->time))->toBe('19:30');
});

it('lets managers set up outlets, services and closed dates', function (): void {
    $manager = staffMember($this->hotel, SystemRole::Manager);

    asStaffOf($this->hotel, $manager)->post(route('hotel.restaurant.outlets.store'), [
        'name' => ['fr' => 'Bar Piscine', 'en' => 'Pool Bar'], 'type' => 'bar', 'seats' => 30, 'default_duration_minutes' => 60,
        'slot_arrival_limit' => null, 'auto_confirm' => true, 'auto_confirm_max_party' => 8, 'active' => true,
    ])->assertSessionHasNoErrors();
    $bar = ($this->in)(fn () => Outlet::query()->where('type', 'bar')->sole());
    expect($bar->public_slug)->toBe('bar-piscine');

    asStaffOf($this->hotel, $manager)->put(route('hotel.restaurant.outlets.periods', $bar), ['periods' => [
        ['weekday' => 5, 'name' => ['fr' => 'Soirée'], 'starts_at' => '18:00', 'ends_at' => '23:30', 'slot_minutes' => 60, 'last_seating_minutes' => 30],
    ]])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $manager)->put(route('hotel.restaurant.outlets.periods', $bar), ['periods' => [
        ['weekday' => 5, 'name' => ['fr' => 'Nuit'], 'starts_at' => '22:00', 'ends_at' => '02:00', 'slot_minutes' => 60, 'last_seating_minutes' => 30],
    ]])->assertSessionHasErrors('periods.0.ends_at');

    asStaffOf($this->hotel, $manager)->post(route('hotel.restaurant.outlets.close', $bar), ['date' => '2026-11-20', 'reason' => 'Privatisation'])->assertRedirect();
    expect(($this->in)(fn () => ServicePeriod::query()->where('outlet_id', $bar->id)->count()))->toBe(1);

    asStaffOf($this->hotel, $this->staff)->get(route('hotel.restaurant.outlets'))->assertForbidden();
});

it('keeps each role to its permissions and each hotel to its own bookings', function (): void {
    $booking = bookTable($this->hotel, $this->outlet, '2026-11-13', '20:00', 4);

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))->get(route('hotel.restaurant'))->assertOk();
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))->post(route('hotel.restaurant.bookings.status', $booking), ['status' => 'arrived'])->assertForbidden();
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->post(route('hotel.restaurant.bookings.status', $booking), ['status' => 'arrived'])->assertSessionHasNoErrors();

    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->post(route('hotel.restaurant.bookings.status', $booking), ['status' => 'cancelled'])->assertNotFound();
});
