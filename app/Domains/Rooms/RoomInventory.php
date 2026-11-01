<?php

declare(strict_types=1);

namespace App\Domains\Rooms;

use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomClosure;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * How many rooms of each category can be sold on each night: rooms in
 * service, minus rooms closed that night. Holds, bookings, held-back rooms
 * and stop-sell are applied on top of this by AvailabilityService (S2.3).
 *
 * Uses two queries regardless of the number of nights or categories.
 */
final class RoomInventory
{
    /**
     * @param  list<string>|null  $categoryIds  null for all categories
     * @param  CarbonInterface  $from  first night
     * @param  CarbonInterface  $to  last night (inclusive)
     * @return array<string, array<string, int>> category id => [Y-m-d => sellable rooms]
     */
    public function sellable(?array $categoryIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());

        $rooms = Room::query()
            ->where('status', RoomStatus::InService->value)
            ->when($categoryIds !== null, fn ($query) => $query->whereIn('room_category_id', $categoryIds))
            ->get(['id', 'room_category_id']);

        $closures = RoomClosure::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->where('from_date', '<=', $to->toDateString())
            ->where('to_date', '>=', $from->toDateString())
            ->get(['room_id', 'from_date', 'to_date']);

        $categoryOf = $rooms->pluck('room_category_id', 'id');
        $nights = array_map(
            fn (CarbonInterface $night): string => $night->toDateString(),
            iterator_to_array(CarbonPeriod::create($from, $to)),
        );

        $result = [];
        foreach ($categoryIds ?? $rooms->pluck('room_category_id')->unique()->all() as $categoryId) {
            $result[(string) $categoryId] = array_fill_keys($nights, 0);
        }

        foreach ($rooms as $room) {
            foreach ($nights as $night) {
                $result[$room->room_category_id][$night]++;
            }
        }

        foreach ($closures as $closure) {
            $categoryId = (string) $categoryOf[$closure->room_id];
            $start = max($closure->from_date->toDateString(), $from->toDateString());
            $end = min($closure->to_date->toDateString(), $to->toDateString());

            foreach (CarbonPeriod::create($start, $end) as $night) {
                $key = $night->toDateString();
                $result[$categoryId][$key] = max(0, $result[$categoryId][$key] - 1);
            }
        }

        return $result;
    }

    public function sellableOn(string $categoryId, CarbonInterface $night): int
    {
        return $this->sellable([$categoryId], $night, $night)[$categoryId][$night->toDateString()] ?? 0;
    }
}
