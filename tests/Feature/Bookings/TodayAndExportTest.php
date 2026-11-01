<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\RegisterCheck;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomClosure;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    // 23:30 UTC on the 11th is already the 12th in Dhaka: "today" must follow the hotel's time zone.
    $this->travelTo('2026-11-11 23:30:00');
    $this->hotel = Hotel::factory()->create(['name' => 'First Class Lodge', 'currency' => 'XOF', 'timezone' => 'Asia/Dhaka']);
    $this->exe = roomCategory($this->hotel, 'EXE', 4);
    rateFor($this->hotel, $this->exe);
    $this->context = app(TenantContext::class);
    $this->in = fn (callable $callback) => $this->context->run($this->hotel, $callback);
    $this->rooms = ($this->in)(fn () => Room::query()->orderBy('number')->get());
});

function seedFrontDesk(object $test): array
{
    $test->travelTo('2026-11-10 08:00:00');
    $inHouse = book($test->hotel, $test->exe, '2026-11-10', '2026-11-14', phone: '+2250700000001');
    $leaving = book($test->hotel, $test->exe, '2026-11-10', '2026-11-12', phone: '+2250700000002');
    ($test->in)(function () use ($test, $inHouse, $leaving): void {
        $life = app(BookingLifecycle::class);
        $life->checkIn($inHouse, [1 => $test->rooms[0]->id]);
        $life->checkIn($leaving, [1 => $test->rooms[1]->id]);
    });
    $missed = book($test->hotel, $test->exe, '2026-11-11', '2026-11-13', phone: '+2250700000003');

    $test->travelTo('2026-11-11 23:30:00');
    $arriving = book($test->hotel, $test->exe, '2026-11-12', '2026-11-13', phone: '+2250700000004');
    $tentative = book($test->hotel, $test->exe, '2026-11-20', '2026-11-21', phone: '+2250700000005', status: BookingStatus::Tentative);

    return compact('inHouse', 'leaving', 'missed', 'arriving', 'tentative');
}

it('lists arrivals, in-house guests, departures, no-shows and tentative bookings in the hotel time zone', function (): void {
    $b = seedFrontDesk($this);

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))
        ->get(route('hotel.today'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hotel/today')
            ->where('date', '2026-11-12')
            ->where('arrivals.0.reference', $b['arriving']->reference)
            ->has('arrivals', 1)
            ->where('arrivals.0.suggested_rooms.1', $this->rooms[1]->id)
            ->has('inHouse', 2)
            ->has('departures', 1)
            ->where('departures.0.reference', $b['leaving']->reference)
            ->has('noShows', 1)
            ->where('noShows.0.reference', $b['missed']->reference)
            ->has('tentative', 1)
            ->where('freeTonight.0.available', 1));
});

it('shows every room tonight on the rooms board', function (): void {
    seedFrontDesk($this);
    ($this->in)(fn () => RoomClosure::query()->create(['room_id' => $this->rooms[3]->id, 'from_date' => '2026-11-12', 'to_date' => '2026-11-12']));

    $board = collect(($this->in)(fn () => app(App\Domains\Bookings\FrontDesk::class)->roomsBoard()))->keyBy('number');

    expect($board[$this->rooms[0]->number]['state'])->toBe('occupied')
        ->and($board[$this->rooms[1]->number]['state'])->toBe('free')
        ->and($board[$this->rooms[1]->number]['departing'])->toBeTrue()
        ->and($board[$this->rooms[2]->number]['state'])->toBe('free')
        ->and($board[$this->rooms[3]->number]['state'])->toBe('out_of_service');
});

it('checks a guest in from the Today screen with the suggested room', function (): void {
    $b = seedFrontDesk($this);
    $reception = staffMember($this->hotel, SystemRole::Reception);

    $suggested = ($this->in)(fn () => app(App\Domains\Bookings\FrontDesk::class)->suggestedRooms($b['arriving']->load('bookingRooms')));

    asStaffOf($this->hotel, $reception)->post(route('hotel.bookings.check-in', $b['arriving']), ['rooms' => $suggested])->assertRedirect();

    expect(($this->in)(fn () => $b['arriving']->fresh()->status))->toBe(BookingStatus::CheckedIn);
});

it('saves the evening register check with tonight\'s rooms and audits it', function (): void {
    seedFrontDesk($this);
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->post(route('hotel.today.register-check'), ['matches' => false])->assertSessionHasErrors('notes');
    asStaffOf($this->hotel, $reception)->post(route('hotel.today.register-check'), ['matches' => true])->assertSessionHasNoErrors();

    $check = ($this->in)(fn () => RegisterCheck::query()->sole());
    expect($check->night->toDateString())->toBe('2026-11-12')
        ->and($check->rooms)->toBe([$this->rooms[0]->number])
        ->and(AuditLog::query()->where('event', 'bookings.register_checked')->exists())->toBeTrue();

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))->post(route('hotel.today.register-check'), ['matches' => true])->assertForbidden();
});

it('sends hotel staff who handle bookings to the Today screen', function (): void {
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))
        ->get(route('dashboard'))
        ->assertRedirect(route('hotel.today'));
});

it('exports bookings in a date range as CSV and audits the export', function (): void {
    seedFrontDesk($this);
    $manager = staffMember($this->hotel, SystemRole::Manager);

    $response = asStaffOf($this->hotel, $manager)->get(route('hotel.bookings.export', ['from' => '2026-11-12', 'to' => '2026-11-12']));
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim(substr($csv, 3)))));

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($rows[0][0])->toBe('reference')
        // In house, missed arrival and today's arrival; the departure's last night was the 11th.
        ->and(count($rows) - 1)->toBe(3)
        ->and(collect($rows)->pluck(0))->not->toContain(($this->in)(fn () => App\Domains\Bookings\Models\Booking::query()->where('status', 'tentative')->value('reference')));

    $audit = AuditLog::query()->where('event', 'bookings.exported')->sole();
    expect($audit->properties['rows'])->toBe(3);
});

it('neutralises spreadsheet formulas in exported names', function (): void {
    $this->travelTo('2026-11-11 23:30:00');
    $booking = book($this->hotel, $this->exe, '2026-11-12', '2026-11-13');
    ($this->in)(fn () => $booking->guest->update(['first_name' => '=HYPERLINK("x")']));

    $csv = asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))
        ->get(route('hotel.bookings.export', ['from' => '2026-11-12', 'to' => '2026-11-12']))
        ->streamedContent();

    expect($csv)->toContain("'=HYPERLINK");
});

it('limits exports to people allowed to export', function (): void {
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))
        ->get(route('hotel.bookings.export', ['from' => '2026-11-01', 'to' => '2026-11-30']))
        ->assertForbidden();

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))
        ->get(route('hotel.bookings.export', ['from' => '2026-11-01', 'to' => '2026-11-30']))
        ->assertOk();
});

it('exports only the current hotel\'s bookings', function (): void {
    $other = Hotel::factory()->create();
    $otherCategory = roomCategory($other, 'X', 1);
    rateFor($other, $otherCategory);
    $this->travelTo('2026-11-11 23:30:00');
    book($other, $otherCategory, '2026-11-15', '2026-11-16');

    $csv = asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))
        ->get(route('hotel.bookings.export', ['from' => '2026-11-01', 'to' => '2026-11-30']))
        ->streamedContent();

    expect(count(array_filter(explode("\n", trim($csv)))))->toBe(1);
});
