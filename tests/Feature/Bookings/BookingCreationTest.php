<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Availability\HoldStatus;
use App\Domains\Availability\Models\InventoryDay;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Exceptions\BookingNotPossible;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\Guest;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    $this->hotel = Hotel::factory()->create(['name' => 'First Class Lodge', 'currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $this->exe = roomCategory($this->hotel, 'EXE', 3);
    rateFor($this->hotel, $this->exe);
    $this->context = app(TenantContext::class);
});

it('books the nights of a confirmed booking and saves the quoted price', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-12', '2026-11-14', rooms: 2, adults: 2);

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->reference)->toBe('FCL-26-0001')
        ->and($booking->total_minor)->toBe(100000)
        ->and($booking->price_snapshot['nights'])->toHaveCount(2)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-14'))->toBe(['2026-11-12' => 1, '2026-11-13' => 1, '2026-11-14' => 3])
        ->and($this->context->run($this->hotel, fn () => $booking->bookingRooms()->count()))->toBe(2);
});

it('gives every booking a unique reference in sequence', function (): void {
    $big = roomCategory($this->hotel, 'BIG', 60);
    rateFor($this->hotel, $big);

    $references = collect(range(1, 50))->map(fn (int $i): string => book($this->hotel, $big, '2026-11-12', '2026-11-13', phone: '+22507000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT))->reference);

    expect($references->unique()->count())->toBe(50)
        ->and($references->last())->toBe('FCL-26-0050');
});

it('uses the hotel booking prefix and restarts numbering each year', function (): void {
    $this->context->run($this->hotel, fn () => HotelSettings::current()->update(['booking_prefix' => 'LODGE']));
    expect(book($this->hotel, $this->exe, '2026-11-12', '2026-11-13')->reference)->toBe('LODGE-26-0001');

    $this->travelTo('2027-01-02 10:00:00');
    expect(book($this->hotel, $this->exe, '2027-01-12', '2027-01-13')->reference)->toBe('LODGE-27-0001');
});

it('refuses stays the quote engine flags, and books nothing', function (): void {
    book($this->hotel, $this->exe, '2026-11-12', '2026-11-13', rooms: 3, adults: 3);

    expect(fn () => book($this->hotel, $this->exe, '2026-11-12', '2026-11-13'))->toThrow(BookingNotPossible::class)
        ->and(fn () => book($this->hotel, $this->exe, '2026-11-20', '2026-11-21', adults: 5))->toThrow(BookingNotPossible::class);

    expect($this->context->run($this->hotel, fn () => Booking::query()->count()))->toBe(1);
});

it('holds the rooms of a tentative booking until its deadline', function (): void {
    $this->context->run($this->hotel, fn () => HotelSettings::current()->update(['tentative_hours' => 24]));

    $booking = book($this->hotel, $this->exe, '2026-11-12', '2026-11-13', rooms: 3, status: BookingStatus::Tentative, adults: 3);
    $hold = $this->context->run($this->hotel, fn () => App\Domains\Availability\Models\InventoryHold::query()->findOrFail($booking->inventory_hold_id));

    expect($booking->tentative_until?->toDateTimeString())->toBe('2026-11-02 10:00:00')
        ->and($hold->status)->toBe(HoldStatus::Active)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-12'))->toBe(['2026-11-12' => 0])
        ->and($this->context->run($this->hotel, fn () => (int) InventoryDay::query()->sum('booked')))->toBe(0);
});

it('records a price override with its reason and keeps the saved price when rates change', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-12', '2026-11-14', override: Money::of(45000, 'XOF'), reason: 'Corporate rate');

    $this->context->run($this->hotel, fn () => App\Domains\Rates\Models\CategoryRate::query()->update(['base_minor' => 99000]));

    $booking = $this->context->run($this->hotel, fn () => $booking->fresh());
    expect($booking->total_minor)->toBe(45000)
        ->and($booking->price_overridden)->toBeTrue()
        ->and($booking->override_reason)->toBe('Corporate rate')
        ->and($booking->price_snapshot['total_minor'])->toBe(50000)
        ->and(AuditLog::query()->where('event', 'bookings.created')->sole()->properties['override_reason'])->toBe('Corporate rate');
});

it('creates a booking from the quick booking form and recognises returning guests', function (): void {
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)
        ->post(route('hotel.bookings.store'), bookingPayload($this->exe))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    asStaffOf($this->hotel, $reception)
        ->post(route('hotel.bookings.store'), bookingPayload($this->exe, ['check_in' => '2026-11-20', 'check_out' => '2026-11-21', 'phone' => '+2250711223344']))
        ->assertSessionHasNoErrors();

    $guests = $this->context->run($this->hotel, fn () => Guest::query()->get());
    expect($guests)->toHaveCount(1)
        ->and($guests->first()->phone_e164)->toBe('+2250711223344')
        ->and($this->context->run($this->hotel, fn () => $guests->first()->bookings()->count()))->toBe(2);

    asStaffOf($this->hotel, $reception)
        ->get(route('hotel.bookings.create', ['phone' => '+225 07 11 22 33 44']), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new App\Http\Middleware\HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'hotel/bookings/create',
            'X-Inertia-Partial-Data' => 'guest',
        ])
        ->assertJsonPath('props.guest.first_name', 'Moussa')
        ->assertJsonPath('props.guest.bookings', 2);
});

it('shows booking errors on the form', function (): void {
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))
        ->post(route('hotel.bookings.store'), bookingPayload($this->exe, ['rooms' => 4, 'adults' => 4]))
        ->assertSessionHasErrors('room_category_id');
});

it('needs the override permission to change the price', function (): void {
    $payload = bookingPayload($this->exe, ['override_total' => '40000', 'override_reason' => 'Friend']);

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->post(route('hotel.bookings.store'), $payload)->assertForbidden();
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))->post(route('hotel.bookings.store'), $payload)->assertSessionHasNoErrors();
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))
        ->post(route('hotel.bookings.store'), bookingPayload($this->exe, ['check_in' => '2026-11-20', 'check_out' => '2026-11-21', 'override_total' => '40000']))
        ->assertSessionHasErrors('override_reason');
});

it('lists, searches and shows bookings', function (): void {
    $booking = book($this->hotel, $this->exe, '2026-11-12', '2026-11-14');
    $readOnly = staffMember($this->hotel, SystemRole::ReadOnly);

    asStaffOf($this->hotel, $readOnly)->get(route('hotel.bookings.index', ['search' => 'Koné']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('bookings.data', 1)->where('canCreate', false));
    asStaffOf($this->hotel, $readOnly)->get(route('hotel.bookings.index', ['search' => 'nobody']))
        ->assertInertia(fn ($page) => $page->has('bookings.data', 0));
    asStaffOf($this->hotel, $readOnly)->get(route('hotel.bookings.show', $booking))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('booking.reference', 'FCL-26-0001')->has('timeline', 1));
    asStaffOf($this->hotel, $readOnly)->get(route('hotel.bookings.create'))->assertForbidden();
});

it('keeps bookings and guests separate between hotels', function (): void {
    $other = Hotel::factory()->create(['name' => 'Other Hotel']);
    $otherCategory = roomCategory($other, 'X', 2);
    rateFor($other, $otherCategory);
    $foreign = book($other, $otherCategory, '2026-11-12', '2026-11-13', phone: '+2250711223344');
    $manager = staffMember($this->hotel, SystemRole::Manager);

    asStaffOf($this->hotel, $manager)->get(route('hotel.bookings.show', $foreign))->assertNotFound();
    asStaffOf($this->hotel, $manager)->post(route('hotel.bookings.store'), bookingPayload($otherCategory))->assertSessionHasErrors('room_category_id');

    // The same phone number is a separate guest in each hotel.
    asStaffOf($this->hotel, $manager)->post(route('hotel.bookings.store'), bookingPayload($this->exe))->assertSessionHasNoErrors();
    expect($this->context->run($this->hotel, fn () => Guest::query()->count()))->toBe(1)
        ->and($this->context->run($other, fn () => Guest::query()->count()))->toBe(1)
        ->and(book($other, $otherCategory, '2026-11-20', '2026-11-21')->reference)->toStartWith('OH-26-');
});

it('rebuilds booked counts from bookings', function (): void {
    book($this->hotel, $this->exe, '2026-11-12', '2026-11-14', rooms: 2);
    book($this->hotel, $this->exe, '2026-11-13', '2026-11-15', status: BookingStatus::Tentative);

    $this->context->run($this->hotel, fn () => InventoryDay::query()->update(['booked' => 0]));
    $this->artisan('inventory:rebuild')->assertSuccessful();

    expect(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-14'))
        ->toBe(['2026-11-12' => 1, '2026-11-13' => 0, '2026-11-14' => 2]);
});
