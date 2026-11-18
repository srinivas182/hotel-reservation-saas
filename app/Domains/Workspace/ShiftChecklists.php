<?php

declare(strict_types=1);

namespace App\Domains\Workspace;

use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Domains\Workspace\Models\ShiftChecklist;
use App\Domains\Workspace\Models\ShiftChecklistTick;
use App\Support\RequestMemo;
use Carbon\CarbonImmutable;

/**
 * The shift checklists of a hotel day: what is done, by whom, what is left.
 */
final class ShiftChecklists
{
    public function __construct(private readonly TenantContext $context) {}

    public function today(): CarbonImmutable
    {
        return HotelClock::today($this->context->hotel());
    }

    /**
     * @return list<array{id: int, name: string, shift: string, items: list<array{text: string, key: string, done: bool, by: string|null, at: string|null}>, done: int, total: int}>
     */
    public function forDay(?CarbonImmutable $day = null): array
    {
        $day ??= $this->today();

        return RequestMemo::remember('checklists|'.$this->context->id().'|'.$day->toDateString(), fn (): array => $this->build($day));
    }

    /**
     * @return list<array{id: int, name: string, shift: string, items: list<array{text: string, key: string, done: bool, by: string|null, at: string|null}>, done: int, total: int}>
     */
    private function build(CarbonImmutable $day): array
    {
        $timezone = $this->context->hotel()->timezone;
        $lists = ShiftChecklist::query()->where('active', true)->orderBy('sort_order')->orderBy('id')->get();
        $ticks = ShiftChecklistTick::query()->with('user')->where('day', $day->toDateString())->get()->groupBy('shift_checklist_id');
        $out = [];

        foreach ($lists as $list) {
            $byKey = ($ticks[$list->id] ?? collect())->keyBy('item_key');
            $items = [];
            foreach ($list->items as $text) {
                $tick = $byKey[ShiftChecklist::itemKey($text)] ?? null;
                $items[] = [
                    'text' => $text,
                    'key' => ShiftChecklist::itemKey($text),
                    'done' => $tick !== null,
                    'by' => $tick?->user?->first_name,
                    'at' => $tick?->created_at?->setTimezone($timezone)->format('H:i'),
                ];
            }

            $out[] = [
                'id' => $list->id,
                'name' => $list->name,
                'shift' => $list->shift,
                'items' => $items,
                'done' => count(array_filter($items, fn (array $i): bool => $i['done'])),
                'total' => count($items),
            ];
        }

        return $out;
    }

    /** After a tick: read again. */
    public function forget(): void
    {
        RequestMemo::forget('checklists|');
    }
}
