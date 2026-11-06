<?php

declare(strict_types=1);

namespace App\Domains\Events;

use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\EventBlock;
use App\Domains\Events\Models\EventSpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which events hold which spaces. Two events clash on the same date when
 * they use a common physical part (a combined space uses its parts) and their
 * time blocks overlap (a full day covers the morning and afternoon).
 */
final class EventAvailability
{
    public function clash(EventSpace $space, EventBlock $block, CarbonImmutable $date, ?string $ignoreEventId = null): ?Event
    {
        $spaces = EventSpace::query()->get()->keyBy('id');
        $blocks = EventBlock::query()->get()->keyBy('id');
        $wantedSpaces = $this->spaceFootprint($space, $spaces);
        $wantedBlocks = $block->footprint();

        return $this->holders($date, $ignoreEventId)->first(function (Event $e) use ($spaces, $blocks, $wantedSpaces, $wantedBlocks): bool {
            $theirSpace = $spaces->get((string) $e->event_space_id);
            $theirBlock = $blocks->get((int) $e->event_block_id);

            return $theirSpace !== null && $theirBlock !== null
                && array_intersect($wantedSpaces, $this->spaceFootprint($theirSpace, $spaces)) !== []
                && array_intersect($wantedBlocks, $theirBlock->footprint()) !== [];
        });
    }

    /**
     * Events holding a space on a date (live options and confirmed events).
     *
     * @return Collection<int, Event>
     */
    public function holders(CarbonImmutable $date, ?string $ignoreEventId = null): Collection
    {
        return Event::query()
            ->where('date', $date->toDateString())
            ->where('waitlisted', false)
            ->whereNotNull('event_space_id')->whereNotNull('event_block_id')
            ->where(fn ($q) => $q->where('status', EventStatus::Confirmed->value)
                ->orWhere(fn ($o) => $o->where('status', EventStatus::Option->value)->where('option_until', '>', now())))
            ->when($ignoreEventId !== null, fn ($q) => $q->whereKeyNot($ignoreEventId))
            ->get();
    }

    /**
     * The physical parts a space uses: a combined space is its parts, any
     * other space is itself. Two spaces clash when they share a part.
     *
     * @param  Collection<string, EventSpace>  $spaces
     * @return list<string>
     */
    private function spaceFootprint(EventSpace $space, Collection $spaces, int $depth = 0): array
    {
        $parts = $space->combines ?? [];

        if ($parts === [] || $depth > 5) {
            return [$space->id];
        }

        $leaves = [];
        foreach ($parts as $id) {
            $part = $spaces->get($id);
            $leaves = [...$leaves, ...($part === null ? [$id] : $this->spaceFootprint($part, $spaces, $depth + 1))];
        }

        return array_values(array_unique($leaves));
    }
}
