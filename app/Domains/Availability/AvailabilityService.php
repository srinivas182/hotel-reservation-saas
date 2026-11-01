<?php

declare(strict_types=1);

namespace App\Domains\Availability;

use App\Domains\Availability\Models\InventoryDay;
use App\Domains\Availability\Models\InventoryHold;
use App\Domains\Rooms\RoomInventory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * Rooms available to sell, per category and night, for the current hotel:
 *
 *     available = sellable rooms - held back - active holds - booked
 *
 * and 0 on stop-sell nights. Sellable rooms come from RoomInventory (rooms
 * in service minus closures). Uses a fixed number of queries however many
 * nights or categories are asked for.
 */
final class AvailabilityService
{
    public function __construct(private readonly RoomInventory $inventory) {}

    /**
     * @param  list<string>|null  $categoryIds  null for all categories
     * @param  CarbonInterface  $from  first night
     * @param  CarbonInterface  $to  last night (inclusive)
     * @param  string|null  $ignoreHoldId  leave one hold out (when extending or converting it)
     * @return array<string, array<string, array{sellable: int, held_back: int, held: int, booked: int, stop_sell: bool, available: int}>>
     */
    public function forRange(?array $categoryIds, CarbonInterface $from, CarbonInterface $to, ?string $ignoreHoldId = null): array
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());

        $sellable = $this->inventory->sellable($categoryIds, $from, $to);
        $ids = array_keys($sellable);

        $days = InventoryDay::query()
            ->whereIn('room_category_id', $ids)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['room_category_id', 'date', 'held_back', 'booked', 'stop_sell'])
            ->keyBy(fn (InventoryDay $day): string => $day->room_category_id.'|'.$day->date->toDateString());

        $holds = InventoryHold::query()
            ->counting()
            ->whereIn('room_category_id', $ids)
            ->where('check_in', '<=', $to->toDateString())
            ->where('check_out', '>', $from->toDateString())
            ->when($ignoreHoldId !== null, fn ($query) => $query->whereKeyNot($ignoreHoldId))
            ->get(['room_category_id', 'check_in', 'check_out', 'rooms']);

        $held = [];
        foreach ($holds as $hold) {
            $start = max($hold->check_in->toDateString(), $from->toDateString());
            $last = min($hold->check_out->subDay()->toDateString(), $to->toDateString());

            foreach (CarbonPeriod::create($start, $last) as $night) {
                $key = $hold->room_category_id.'|'.$night->toDateString();
                $held[$key] = ($held[$key] ?? 0) + $hold->rooms;
            }
        }

        $result = [];
        foreach ($sellable as $categoryId => $nights) {
            foreach ($nights as $date => $rooms) {
                $key = $categoryId.'|'.$date;
                /** @var InventoryDay|null $day */
                $day = $days->get($key);
                $heldBack = $day->held_back ?? 0;
                $booked = $day->booked ?? 0;
                $stopSell = $day->stop_sell ?? false;
                $heldNow = $held[$key] ?? 0;

                $result[$categoryId][$date] = [
                    'sellable' => $rooms,
                    'held_back' => $heldBack,
                    'held' => $heldNow,
                    'booked' => $booked,
                    'stop_sell' => $stopSell,
                    'available' => $stopSell ? 0 : max(0, $rooms - $heldBack - $heldNow - $booked),
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<string, int> Y-m-d => available rooms
     */
    public function availableFor(string $categoryId, CarbonInterface $from, CarbonInterface $to, ?string $ignoreHoldId = null): array
    {
        return array_map(
            fn (array $night): int => $night['available'],
            $this->forRange([$categoryId], $from, $to, $ignoreHoldId)[$categoryId] ?? [],
        );
    }
}
