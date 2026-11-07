<?php

declare(strict_types=1);

use App\Domains\Events\EventService;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\EventBlock;
use App\Domains\Events\Models\EventSpace;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

/**
 * Salle A (60 theatre / 40 banquet), Salle B (40 theatre), Grande salle
 * (A + B, 100 theatre); morning, afternoon, evening, and full day (morning +
 * afternoon).
 *
 * @return array{a: EventSpace, b: EventSpace, grande: EventSpace, morning: EventBlock, afternoon: EventBlock, evening: EventBlock, day: EventBlock}
 */
function eventSetup(Hotel $hotel): array
{
    return app(TenantContext::class)->run($hotel, function (): array {
        $a = EventSpace::query()->create(['name' => ['fr' => 'Salle A'], 'capacities' => ['theatre' => 60, 'banquet' => 40]]);
        $b = EventSpace::query()->create(['name' => ['fr' => 'Salle B'], 'capacities' => ['theatre' => 40]]);
        $grande = EventSpace::query()->create(['name' => ['fr' => 'Grande salle'], 'capacities' => ['theatre' => 100, 'banquet' => 80], 'combines' => [$a->id, $b->id]]);
        $morning = EventBlock::query()->create(['name' => ['fr' => 'Matin'], 'starts_at' => '08:00', 'ends_at' => '13:00']);
        $afternoon = EventBlock::query()->create(['name' => ['fr' => 'Après-midi'], 'starts_at' => '14:00', 'ends_at' => '18:00']);
        $evening = EventBlock::query()->create(['name' => ['fr' => 'Soirée'], 'starts_at' => '19:00', 'ends_at' => '23:59']);
        $day = EventBlock::query()->create(['name' => ['fr' => 'Journée'], 'starts_at' => '08:00', 'ends_at' => '18:00', 'covers' => [$morning->id, $afternoon->id]]);

        return compact('a', 'b', 'grande', 'morning', 'afternoon', 'evening', 'day');
    });
}

function eventFor(Hotel $hotel, EventSpace $space, EventBlock $block, string $date = '2026-12-12', int $people = 30, ?string $layout = 'theatre', string $client = 'Société Ivoire'): Event
{
    return app(TenantContext::class)->run($hotel, fn (): Event => app(EventService::class)->enquire([
        'client_name' => 'Awa Koné', 'company' => $client, 'type' => 'seminar', 'source' => 'phone',
        'date' => $date, 'event_space_id' => $space->id, 'event_block_id' => $block->id, 'people' => $people, 'layout' => $layout,
    ]));
}

/**
 * A sent and accepted quote for the event (a single free line), with the
 * deposit recorded, so the event can be confirmed.
 */
function acceptQuoteAndPayDeposit(Hotel $hotel, Event $event, App\Models\User $user, int $amountMinor = 100000): App\Domains\Events\Models\EventQuote
{
    return app(TenantContext::class)->run($hotel, function () use ($hotel, $event, $user, $amountMinor) {
        $quotes = app(App\Domains\Events\Quotes::class);
        $quote = $quotes->create($event, [['kind' => 'free', 'description' => 'Forfait', 'quantity' => 1, 'unit_price' => $amountMinor]], 0, null, null, null, $user);
        $quotes->send($quote, $user);
        $quotes->accept($quote->fresh(), 'Awa Koné', null, null, 'staff', $user);
        $deposit = $quotes->depositDue($quote->fresh());
        if ($deposit > 0) {
            app(App\Domains\Folio\Folio::class)->pay($event, Brick\Money\Money::ofMinor($deposit, $hotel->currency), 'cash', null, $user);
        }

        return $quote->fresh();
    });
}
