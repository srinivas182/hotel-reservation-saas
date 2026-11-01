<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Availability\Models\InventoryDay;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $this->exe = roomCategory($this->hotel, 'EXE', 3);
    rateFor($this->hotel, $this->exe);
    overrideFor($this->hotel, $this->exe, '2026-11-14', '2026-11-14', ['rate_minor' => 30000, 'label' => 'Festival']);
});

it('shows availability and prices per category and night', function (): void {
    holdRooms($this->hotel, $this->exe, '2026-11-14', '2026-11-15', 1);

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))
        ->get(route('hotel.calendar', ['start' => '2026-11-13', 'days' => 14]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hotel/calendar')
            ->has('dates', 14)
            ->where("cells.{$this->exe->id}.2026-11-13.available", 3)
            ->where("cells.{$this->exe->id}.2026-11-14.available", 2)
            ->where("cells.{$this->exe->id}.2026-11-14.held", 1)
            ->where("cells.{$this->exe->id}.2026-11-14.label", 'Festival')
            ->where("cells.{$this->exe->id}.2026-11-14.rate", '30 000')
            ->has('holds', 1));
});

it('stops and reopens sales and holds rooms back over a range', function (): void {
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->post(route('hotel.calendar.stop-sell'), [
        'category_ids' => [$this->exe->id], 'from' => '2026-11-20', 'to' => '2026-11-22', 'stop_sell' => true,
    ])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $reception)->post(route('hotel.calendar.hold-back'), [
        'category_ids' => [$this->exe->id], 'from' => '2026-11-25', 'to' => '2026-11-25', 'rooms' => 2,
    ])->assertSessionHasNoErrors();

    expect(availableNights($this->hotel, $this->exe, '2026-11-20', '2026-11-23'))
        ->toBe(['2026-11-20' => 0, '2026-11-21' => 0, '2026-11-22' => 0, '2026-11-23' => 3])
        ->and(availableNights($this->hotel, $this->exe, '2026-11-25', '2026-11-25'))->toBe(['2026-11-25' => 1]);

    asStaffOf($this->hotel, $reception)->post(route('hotel.calendar.stop-sell'), [
        'category_ids' => [$this->exe->id], 'from' => '2026-11-21', 'to' => '2026-11-21', 'stop_sell' => false,
    ]);
    expect(availableNights($this->hotel, $this->exe, '2026-11-21', '2026-11-21'))->toBe(['2026-11-21' => 3]);
});

it('lets read-only staff view the calendar but not change it', function (): void {
    $readOnly = staffMember($this->hotel, SystemRole::ReadOnly);

    asStaffOf($this->hotel, $readOnly)->get(route('hotel.calendar'))->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false));
    asStaffOf($this->hotel, $readOnly)->post(route('hotel.calendar.stop-sell'), [
        'category_ids' => [$this->exe->id], 'from' => '2026-11-20', 'to' => '2026-11-20', 'stop_sell' => true,
    ])->assertForbidden();
});

it('refuses another hotel\'s categories in range actions', function (): void {
    $other = Hotel::factory()->create();
    $foreign = roomCategory($other, 'X', 1);

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))->post(route('hotel.calendar.stop-sell'), [
        'category_ids' => [$foreign->id], 'from' => '2026-11-20', 'to' => '2026-11-20', 'stop_sell' => true,
    ])->assertSessionHasErrors('category_ids.0');

    expect(app(TenantContext::class)->run($other, fn () => InventoryDay::query()->count()))->toBe(0);
});

it('quotes a stay from the calendar', function (): void {
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))
        ->get(route('hotel.calendar', ['category' => $this->exe->id, 'check_in' => '2026-11-13', 'check_out' => '2026-11-15', 'adults' => 2, 'rooms' => 1]), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new App\Http\Middleware\HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'hotel/calendar',
            'X-Inertia-Partial-Data' => 'quote',
        ])
        ->assertOk()
        ->assertJsonPath('props.quote.bookable', true)
        ->assertJsonPath('props.quote.nights.1.label', 'Festival');
});
