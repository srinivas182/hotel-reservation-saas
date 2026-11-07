<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Availability\AvailabilityService;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Events\Models\RoomBlock;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-13 10:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $this->category = roomCategory($this->hotel, 'EXE', 5);
    rateFor($this->hotel, $this->category);
    $this->s = eventSetup($this->hotel);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->coordinator = staffMember($this->hotel, SystemRole::EventsCoordinator);
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
    $this->event = eventFor($this->hotel, $this->s['grande'], $this->s['day'], people: 60);
    $this->free = fn (): array => ($this->in)(fn () => app(AvailabilityService::class)->availableFor($this->category->id, CarbonImmutable::parse('2026-12-11'), CarbonImmutable::parse('2026-12-12')));
});

it('blocks rooms at a group rate, books attendees from the block, and releases the rest at the cut-off', function (): void {
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.room-blocks.store', $this->event), [
        'room_category_id' => $this->category->id, 'check_in' => '2026-12-11', 'check_out' => '2026-12-13', 'rooms' => 3, 'rate' => 18000, 'cutoff_date' => '2026-11-20',
    ])->assertSessionHasNoErrors();
    expect(($this->free)())->toBe(['2026-12-11' => 2, '2026-12-12' => 2]);

    $block = ($this->in)(fn () => RoomBlock::query()->sole());
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.room-blocks.book', $block), ['first_name' => 'Yao', 'last_name' => 'Kouassi', 'phone' => '+2250700000020', 'adults' => 1])->assertSessionHasNoErrors();

    $booking = ($this->in)(fn () => Booking::query()->sole());
    expect($booking->event_id)->toBe($this->event->id)
        ->and($booking->group_label)->toBe($this->event->reference)
        ->and($booking->total_minor)->toBe(36000)
        ->and($booking->price_overridden)->toBeTrue()
        ->and(($this->free)())->toBe(['2026-12-11' => 2, '2026-12-12' => 2])
        ->and(($this->in)(fn () => $block->fresh()->remaining()))->toBe(2);

    // After the cut-off day the two unused rooms are back on sale.
    $this->travelTo('2026-11-21 08:00:00');
    expect(($this->free)())->toBe(['2026-12-11' => 4, '2026-12-12' => 4]);
    $this->artisan('events:release-room-blocks')->assertSuccessful();
    expect(($this->in)(fn () => $block->fresh()->released_at))->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'events.block_released')->sole()->properties['unused'])->toBe(2);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.room-blocks.book', $block), ['first_name' => 'Late', 'adults' => 1])->assertSessionHasErrors('block');
});

it('refuses blocks the hotel cannot hold and keeps bookings to the right people', function (): void {
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.room-blocks.store', $this->event), [
        'room_category_id' => $this->category->id, 'check_in' => '2026-12-11', 'check_out' => '2026-12-13', 'rooms' => 6, 'rate' => 18000, 'cutoff_date' => '2026-11-20',
    ])->assertSessionHasErrors('rooms');
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.room-blocks.store', $this->event), [
        'room_category_id' => $this->category->id, 'check_in' => '2026-12-11', 'check_out' => '2026-12-13', 'rooms' => 1, 'rate' => 18000, 'cutoff_date' => '2026-12-20',
    ])->assertSessionHasErrors('rooms');
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.room-blocks.store', $this->event), [
        'room_category_id' => $this->category->id, 'check_in' => '2026-12-11', 'check_out' => '2026-12-12', 'rooms' => 1, 'rate' => 18000, 'cutoff_date' => '2026-11-20',
    ])->assertSessionHasNoErrors();

    $block = ($this->in)(fn () => RoomBlock::query()->sole());
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))->post(route('hotel.room-blocks.book', $block), ['first_name' => 'X', 'adults' => 1])->assertForbidden();
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.room-blocks.book', $block), ['first_name' => 'A', 'adults' => 1])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.room-blocks.book', $block), ['first_name' => 'B', 'adults' => 1])->assertSessionHasErrors('block');

    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->post(route('hotel.room-blocks.release', $block))->assertNotFound();
});
