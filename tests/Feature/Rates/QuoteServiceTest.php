<?php

declare(strict_types=1);

use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Rates\BreakfastMode;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomClosure;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $this->exe = roomCategory($this->hotel, 'EXE', 3, ['standard_occupancy' => 2, 'max_adults' => 2, 'max_children' => 1, 'max_occupancy' => 3, 'extra_bed_allowed' => true]);
    $this->suite = roomCategory($this->hotel, 'SUI', 2, ['standard_occupancy' => 2, 'max_adults' => 3, 'max_children' => 2, 'max_occupancy' => 4]);
    rateFor($this->hotel, $this->exe);
    rateFor($this->hotel, $this->suite, ['base_minor' => 40000]);
});

it('prices a simple stay from the base rate', function (): void {
    // Thursday 12 to Saturday 14 November 2026: two nights.
    $quote = quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-14');

    expect($quote->bookable())->toBeTrue()
        ->and(lineAmounts($quote))->toBe(['room' => 'XOF 50000'])
        ->and((string) $quote->total)->toBe('XOF 50000')
        ->and(array_column($quote->nights, 'available'))->toBe([3, 3]);
});

it('applies weekday overrides', function (): void {
    overrideFor($this->hotel, $this->exe, '2026-11-01', '2026-11-30', ['rate_minor' => 30000, 'weekdays' => [5, 6], 'label' => 'Weekend']);

    // Thursday, Friday and Saturday nights.
    $quote = quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-15');

    expect(array_map(fn ($night) => (string) $night['rate'], $quote->nights))->toBe(['XOF 25000', 'XOF 30000', 'XOF 30000'])
        ->and($quote->nights[1]['label'])->toBe('Weekend')
        ->and((string) $quote->total)->toBe('XOF 85000');
});

it('lets the most recently changed override set the price when overrides overlap', function (): void {
    overrideFor($this->hotel, null, '2026-11-01', '2026-11-30', ['rate_minor' => 30000, 'label' => 'High season']);
    $this->travel(1)->minutes();
    overrideFor($this->hotel, $this->exe, '2026-11-12', '2026-11-12', ['rate_minor' => 28000, 'label' => 'Promo']);

    $quote = quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-14');

    expect(array_map(fn ($night) => (string) $night['rate'], $quote->nights))->toBe(['XOF 28000', 'XOF 30000']);
});

it('charges extra adults beyond the standard number of guests', function (): void {
    $quote = quoteStay($this->hotel, $this->suite, '2026-11-12', '2026-11-14', adults: 3);

    expect(lineAmounts($quote))->toBe(['room' => 'XOF 80000', 'extra_adult' => 'XOF 10000']);
});

it('charges children by age band', function (): void {
    bands($this->hotel, [['from' => 0, 'to' => 5, 'charge' => 'free'], ['from' => 6, 'to' => 11, 'charge' => 'child']]);

    // A free 4-year-old is never extra; the 9-year-old is the third chargeable guest.
    $quote = quoteStay($this->hotel, $this->suite, '2026-11-12', '2026-11-14', adults: 2, children: [4, 9]);
    expect(lineAmounts($quote))->toBe(['room' => 'XOF 80000', 'child' => 'XOF 5000']);

    // A 13-year-old is above the bands and pays as an adult.
    $teen = quoteStay($this->hotel, $this->suite, '2026-11-12', '2026-11-14', adults: 2, children: [13]);
    expect(lineAmounts($teen))->toBe(['room' => 'XOF 80000', 'extra_adult' => 'XOF 10000']);
});

it('charges the child rate to every child when the hotel has no age bands', function (): void {
    $quote = quoteStay($this->hotel, $this->suite, '2026-11-12', '2026-11-13', adults: 2, children: [null, null]);

    expect(lineAmounts($quote))->toBe(['room' => 'XOF 40000', 'child' => 'XOF 5000']);
});

it('adds an extra bed and breakfast when asked', function (): void {
    $quote = quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-14', adults: 2, breakfast: true, extraBed: true);

    expect(lineAmounts($quote))->toBe([
        'room' => 'XOF 50000',
        'extra_bed' => 'XOF 10000',
        'breakfast' => 'XOF 12000',
    ])->and((string) $quote->total)->toBe('XOF 72000');
});

it('shows included breakfast at no charge and refuses breakfast when not offered', function (): void {
    app(TenantContext::class)->run($this->hotel, fn () => App\Domains\Rates\Models\CategoryRate::query()->where('room_category_id', $this->exe->id)->update(['breakfast_mode' => BreakfastMode::Included]));
    expect(lineAmounts(quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-13', breakfast: true)))
        ->toBe(['room' => 'XOF 25000', 'breakfast_included' => 'XOF 0']);

    app(TenantContext::class)->run($this->hotel, fn () => App\Domains\Rates\Models\CategoryRate::query()->where('room_category_id', $this->exe->id)->update(['breakfast_mode' => BreakfastMode::None]));
    expect(quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-13', breakfast: true)->hasIssue('breakfast_unavailable'))->toBeTrue();
});

it('spreads guests across several rooms', function (): void {
    $quote = quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-14', adults: 4, rooms: 2);

    expect($quote->bookable())->toBeTrue()
        ->and(lineAmounts($quote))->toBe(['room' => 'XOF 100000']);
});

it('applies included VAT and a tourist tax added on top, and every line reconciles', function (): void {
    app(TenantContext::class)->run($this->hotel, function (): void {
        TaxRate::query()->create(['name' => ['fr' => 'TVA'], 'type' => 'percentage', 'percentage_bps' => 1800, 'inclusive' => true, 'applies_to' => ['rooms', 'food_beverage'], 'sort_order' => 1]);
        TaxRate::query()->create(['name' => ['fr' => 'Taxe de séjour'], 'type' => 'fixed_per_person_per_night', 'amount_minor' => 1000, 'inclusive' => false, 'applies_to' => ['rooms'], 'sort_order' => 2]);
    });

    $quote = quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-14', adults: 2, breakfast: true);

    $roomLines = array_map(fn ($line) => (string) $line->amount, $quote->roomTaxes->lines);

    expect($roomLines)->toBe(['XOF 7627', 'XOF 4000'])
        ->and((string) $quote->roomTaxes->net)->toBe('XOF 42373')
        ->and((string) $quote->breakfastTaxes?->lines[0]->amount)->toBe('XOF 1831')
        ->and((string) $quote->subtotal)->toBe('XOF 62000')
        ->and((string) $quote->total)->toBe('XOF 66000');
});

it('reports minimum stay, closed to arrival and stop-sell', function (): void {
    overrideFor($this->hotel, $this->exe, '2026-11-12', '2026-11-12', ['min_nights' => 3, 'closed_to_arrival' => true]);
    overrideFor($this->hotel, $this->exe, '2026-11-20', '2026-11-20', ['stop_sell' => true]);

    $quote = quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-14');
    expect($quote->hasIssue('min_stay'))->toBeTrue()
        ->and($quote->hasIssue('closed_to_arrival'))->toBeTrue()
        ->and($quote->issues[0]->context ?? [])->toBeArray();

    $stopped = quoteStay($this->hotel, $this->exe, '2026-11-19', '2026-11-21');
    expect($stopped->hasIssue('stop_sell'))->toBeTrue()
        ->and($stopped->nights[1]['available'])->toBe(0);
});

it('reports guest limits, bad dates, past dates and long stays', function (): void {
    expect(quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-13', adults: 3)->hasIssue('too_many_guests'))->toBeTrue()
        ->and(quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-13', adults: 1, rooms: 2)->hasIssue('adult_per_room'))->toBeTrue()
        ->and(quoteStay($this->hotel, $this->exe, '2026-11-14', '2026-11-12')->hasIssue('invalid_dates'))->toBeTrue()
        ->and(quoteStay($this->hotel, $this->exe, '2026-10-30', '2026-11-02')->hasIssue('past_date'))->toBeTrue()
        ->and(quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-12-20')->hasIssue('too_long'))->toBeTrue();
});

it('reports when there are not enough rooms, counting closures', function (): void {
    app(TenantContext::class)->run($this->hotel, function (): void {
        $room = Room::query()->where('room_category_id', $this->exe->id)->firstOrFail();
        RoomClosure::query()->create(['room_id' => $room->id, 'from_date' => '2026-11-13', 'to_date' => '2026-11-13', 'reason' => 'Paint']);
    });

    $quote = quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-14', adults: 6, rooms: 3);

    expect(array_column($quote->nights, 'available'))->toBe([3, 2])
        ->and($quote->hasIssue('not_available'))->toBeTrue();
});

it('reports a missing rate or an inactive category', function (): void {
    $noRate = roomCategory($this->hotel, 'NEW', 1);
    $inactive = roomCategory($this->hotel, 'OLD', 1, ['active' => false]);
    rateFor($this->hotel, $inactive);

    expect(quoteStay($this->hotel, $noRate, '2026-11-12', '2026-11-13')->hasIssue('no_rate'))->toBeTrue()
        ->and(quoteStay($this->hotel, $inactive, '2026-11-12', '2026-11-13')->hasIssue('category_unavailable'))->toBeTrue();
});
