<?php

declare(strict_types=1);

use App\Domains\Finance\Taxes\TaxCalculator;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Finance\Taxes\TaxScope;
use App\Domains\Finance\Taxes\TaxType;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

function rate(array $attributes): TaxRate
{
    return (new TaxRate)->forceFill([
        'name' => ['en' => $attributes['label'] ?? 'Tax'],
        'type' => TaxType::Percentage,
        'percentage_bps' => null,
        'amount_minor' => null,
        'inclusive' => false,
        'applies_to' => ['rooms'],
        'active' => true,
        'effective_from' => null,
        'effective_to' => null,
        'sort_order' => 0,
        ...array_diff_key($attributes, ['label' => true]),
    ]);
}

function calc(array $rates, Money $price, int $nights = 1, int $guests = 1, TaxScope $scope = TaxScope::Rooms, string $date = '2026-11-15')
{
    return (new TaxCalculator)->calculate($rates, $price, $scope, CarbonImmutable::parse($date), $nights, $guests);
}

it('adds an exclusive percentage on top of the net price (FCFA)', function (): void {
    $result = calc([rate(['percentage_bps' => 1800])], Money::of(50000, 'XOF'));

    expect((string) $result->net)->toBe('XOF 50000')
        ->and((string) $result->lines[0]->amount)->toBe('XOF 9000')
        ->and((string) $result->total)->toBe('XOF 59000');
});

it('works back an included percentage so net plus tax equals the price exactly', function (): void {
    // 25 000 / 1.18 = 21 186.44 -> tax 3 813.56 rounds to 3 814; net absorbs the rounding.
    $result = calc([rate(['percentage_bps' => 1800, 'inclusive' => true])], Money::of(25000, 'XOF'));

    expect((string) $result->lines[0]->amount)->toBe('XOF 3814')
        ->and((string) $result->net)->toBe('XOF 21186')
        ->and((string) $result->total)->toBe('XOF 25000')
        ->and((string) $result->includedTax())->toBe('XOF 3814');
});

it('charges fixed taxes per night, per person per night and per stay', function (): void {
    $rates = [
        rate(['label' => 'Night', 'type' => TaxType::FixedPerNight, 'amount_minor' => 500]),
        rate(['label' => 'Person', 'type' => TaxType::FixedPerPersonPerNight, 'amount_minor' => 1000]),
        rate(['label' => 'Stay', 'type' => TaxType::FixedPerStay, 'amount_minor' => 2000]),
    ];

    $result = calc($rates, Money::of(50000, 'XOF'), nights: 2, guests: 2);

    expect(array_map(fn ($line) => (string) $line->amount, $result->lines))->toBe(['XOF 1000', 'XOF 4000', 'XOF 2000'])
        ->and((string) $result->total)->toBe('XOF 57000');
});

it('combines included VAT with a tourist tax added on top', function (): void {
    $result = calc([
        rate(['label' => 'VAT', 'percentage_bps' => 1800, 'inclusive' => true, 'sort_order' => 1]),
        rate(['label' => 'Tourist', 'type' => TaxType::FixedPerPersonPerNight, 'amount_minor' => 1000, 'sort_order' => 2]),
    ], Money::of(59000, 'XOF'), nights: 2, guests: 2);

    expect((string) $result->net)->toBe('XOF 50000')
        ->and((string) $result->includedTax())->toBe('XOF 9000')
        ->and((string) $result->addedTax())->toBe('XOF 4000')
        ->and((string) $result->total)->toBe('XOF 63000');
});

it('splits several included percentages without tax on tax', function (): void {
    $result = calc([
        rate(['label' => 'VAT', 'percentage_bps' => 1800, 'inclusive' => true]),
        rate(['label' => 'Service', 'percentage_bps' => 1000, 'inclusive' => true]),
    ], Money::of(12800, 'XOF'));

    expect((string) $result->net)->toBe('XOF 10000')
        ->and(array_map(fn ($line) => (string) $line->amount, $result->lines))->toBe(['XOF 1800', 'XOF 1000']);
});

it('rounds to cents for euro and to paise for rupee', function (): void {
    $eur = calc([rate(['percentage_bps' => 2000, 'inclusive' => true])], Money::of('99.99', 'EUR'));
    expect((string) $eur->lines[0]->amount)->toBe('EUR 16.67')
        ->and((string) $eur->net)->toBe('EUR 83.32');

    $inr = calc([rate(['percentage_bps' => 1200])], Money::of('3499.50', 'INR'));
    expect((string) $inr->lines[0]->amount)->toBe('INR 419.94')
        ->and((string) $inr->total)->toBe('INR 3919.44');
});

it('only applies active taxes for the right service and date', function (): void {
    $rates = [
        rate(['label' => 'Rooms VAT', 'percentage_bps' => 1800]),
        rate(['label' => 'F&B only', 'percentage_bps' => 1000, 'applies_to' => ['food_beverage']]),
        rate(['label' => 'Inactive', 'percentage_bps' => 500, 'active' => false]),
        rate(['label' => 'Starts later', 'percentage_bps' => 500, 'effective_from' => '2027-01-01']),
        rate(['label' => 'Ended', 'percentage_bps' => 500, 'effective_to' => '2026-10-31']),
    ];

    $result = calc($rates, Money::of(10000, 'XOF'));

    expect(count($result->lines))->toBe(1)
        ->and($result->lines[0]->rate->name['en'])->toBe('Rooms VAT');
});

it('returns the price unchanged when no tax applies', function (): void {
    $result = calc([], Money::of(10000, 'XOF'));

    expect((string) $result->net)->toBe('XOF 10000')
        ->and((string) $result->total)->toBe('XOF 10000')
        ->and($result->lines)->toBe([]);
});
