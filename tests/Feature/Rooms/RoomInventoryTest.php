<?php

declare(strict_types=1);

use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomClosure;
use App\Domains\Rooms\RoomInventory;
use App\Domains\Rooms\RoomStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->hotel = Hotel::factory()->create();
    $this->context = app(TenantContext::class);
    $this->standard = roomCategory($this->hotel, 'STD', rooms: 5);
    $this->suite = roomCategory($this->hotel, 'SUI', rooms: 2);
});

it('counts rooms in service and leaves out rooms out of order or in maintenance', function (): void {
    $this->context->run($this->hotel, function (): void {
        $rooms = Room::query()->where('room_category_id', $this->standard->id)->orderBy('number')->get();
        $rooms[0]->update(['status' => RoomStatus::OutOfOrder]);
        $rooms[1]->update(['status' => RoomStatus::Maintenance]);

        expect(app(RoomInventory::class)->sellableOn($this->standard->id, CarbonImmutable::parse('2026-12-01')))->toBe(3)
            ->and(app(RoomInventory::class)->sellableOn($this->suite->id, CarbonImmutable::parse('2026-12-01')))->toBe(2);
    });
});

it('removes closed rooms only on the nights they are closed', function (): void {
    $result = $this->context->run($this->hotel, function (): array {
        $room = Room::query()->where('room_category_id', $this->standard->id)->firstOrFail();
        RoomClosure::query()->create(['room_id' => $room->id, 'from_date' => '2026-12-03', 'to_date' => '2026-12-05', 'reason' => 'Painting']);

        return app(RoomInventory::class)->sellable([$this->standard->id], CarbonImmutable::parse('2026-12-02'), CarbonImmutable::parse('2026-12-06'));
    });

    expect($result[$this->standard->id])->toBe([
        '2026-12-02' => 5,
        '2026-12-03' => 4,
        '2026-12-04' => 4,
        '2026-12-05' => 4,
        '2026-12-06' => 5,
    ]);
});

it('returns zero for a category without rooms and covers all categories when asked', function (): void {
    $empty = roomCategory($this->hotel, 'EMP');

    $all = $this->context->run($this->hotel, fn (): array => app(RoomInventory::class)->sellable(
        [$this->standard->id, $this->suite->id, $empty->id],
        CarbonImmutable::parse('2026-12-01'),
        CarbonImmutable::parse('2026-12-01'),
    ));

    expect($all[$empty->id]['2026-12-01'])->toBe(0)
        ->and($all[$this->suite->id]['2026-12-01'])->toBe(2);
});

it('uses a fixed number of queries however many nights are asked for', function (): void {
    $this->context->run($this->hotel, function (): void {
        DB::enableQueryLog();
        app(RoomInventory::class)->sellable(null, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2027-01-29'));
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);
        DB::disableQueryLog();
    });
});

it('only counts the current hotel\'s rooms', function (): void {
    $other = Hotel::factory()->create();
    roomCategory($other, 'STD', rooms: 9);

    $result = $this->context->run($this->hotel, fn (): array => app(RoomInventory::class)->sellable(null, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-01')));

    expect(array_sum(array_map(fn (array $nights): int => $nights['2026-12-01'], $result)))->toBe(7);
});
