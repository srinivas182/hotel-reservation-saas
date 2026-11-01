<?php

declare(strict_types=1);

namespace App\Domains\Rates\Quote;

use App\Domains\Availability\AvailabilityService;
use App\Domains\Finance\Taxes\TaxCalculator;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Finance\Taxes\TaxScope;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\BreakfastMode;
use App\Domains\Rates\ChildAgeBands;
use App\Domains\Rates\ChildCharge;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rates\NightlyRates;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Prices a stay for the current hotel. It is a pure calculation: nothing
 * is held or saved. Every price shown to staff or guests comes from here.
 *
 * Pricing per room and night:
 * - nightly rate: the most recently changed applicable override with a
 *   price, otherwise the category base rate;
 * - the base rate covers the category's standard number of guests, taken
 *   in this order: adults, children charged as adults, children charged
 *   the child rate. Guests beyond it pay the extra adult or child rate;
 *   children in a free band never pay and are never "extra";
 * - extra bed and breakfast (optional mode) are charged when asked for.
 *
 * Guests are spread as evenly as possible across the rooms asked for.
 * Room charges are taxed with the rooms tax scope; breakfast with the
 * food & beverage scope.
 */
final class QuoteService
{
    public const MAX_NIGHTS = 30;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AvailabilityService $availability,
        private readonly NightlyRates $nightlyRates,
        private readonly TaxCalculator $taxes,
    ) {}

    public function quote(StayRequest $request): Quote
    {
        $hotel = $this->context->hotel();
        $currency = $hotel->currency;
        $zero = Money::zero($currency);
        $issues = [];

        $category = RoomCategory::query()->find($request->categoryId);
        $rate = $category === null ? null : CategoryRate::query()->where('room_category_id', $category->id)->first();

        if ($category === null || ! $category->active) {
            return $this->empty($request, $zero, [new QuoteIssue('category_unavailable', __('This room category is not available.'))]);
        }

        $nights = $request->nights();
        $today = CarbonImmutable::now($hotel->timezone)->startOfDay();

        if ($nights < 1) {
            return $this->empty($request, $zero, [new QuoteIssue('invalid_dates', __('Check-out must be after check-in.'))]);
        }

        if ($nights > self::MAX_NIGHTS) {
            return $this->empty($request, $zero, [new QuoteIssue('too_long', __('Stays longer than :nights nights need the hotel to confirm.', ['nights' => self::MAX_NIGHTS]), ['max' => self::MAX_NIGHTS])]);
        }

        if ($request->checkIn->lt($today)) {
            $issues[] = new QuoteIssue('past_date', __('Check-in cannot be in the past.'));
        }

        if ($rate === null) {
            return $this->empty($request, $zero, [...$issues, new QuoteIssue('no_rate', __('No rate is set for this room category yet.'))]);
        }

        $settings = HotelSettings::current();
        $bands = ChildAgeBands::fromSettings($settings->child_age_bands);

        // Spread guests across rooms and check each room's limits.
        $rooms = $this->distribute($request, $bands);
        $issues = [...$issues, ...$this->occupancyIssues($request, $category, $rate, $rooms)];

        // Nights: price, overrides and availability.
        $lastNight = $request->checkOut->subDay();
        $prices = $this->nightlyRates->forRange([$category->id], $request->checkIn, $lastNight)[$category->id];
        $inventory = $this->availability->forRange([$category->id], $request->checkIn, $lastNight)[$category->id] ?? [];

        $arrival = $prices[$request->checkIn->toDateString()];
        $minNights = $arrival['min_nights'];

        if ($arrival['closed_to_arrival']) {
            $issues[] = new QuoteIssue('closed_to_arrival', __('Arrivals are not possible on this date.'));
        }

        $nightRows = [];
        $stopSell = false;

        foreach ($prices as $date => $price) {
            $nightStopSell = $price['stop_sell'] || ($inventory[$date]['stop_sell'] ?? false);
            $stopSell = $stopSell || $nightStopSell;

            $nightRows[] = [
                'date' => $date,
                'rate' => Money::ofMinor($price['rate_minor'] ?? $rate->base_minor, $currency),
                'label' => $price['label'],
                'available' => $nightStopSell ? 0 : (int) ($inventory[$date]['available'] ?? 0),
            ];
        }

        if ($nights < $minNights) {
            $issues[] = new QuoteIssue('min_stay', __('The minimum stay for these dates is :nights nights.', ['nights' => $minNights]), ['min' => $minNights]);
        }

        $minAvailable = $nightRows === [] ? 0 : min(array_map(fn (array $row): int => $row['available'], $nightRows));

        if ($stopSell) {
            $issues[] = new QuoteIssue('stop_sell', __('This room category is not on sale for some of these nights.'));
        } elseif ($minAvailable < $request->rooms) {
            $issues[] = new QuoteIssue('not_available', __('Not enough rooms are available for these dates.'), [
                'available' => $minAvailable,
            ]);
        }

        // Charges.
        $lines = [];
        $roomCharges = $zero;

        $nightlyTotal = array_reduce($nightRows, fn (Money $carry, array $row): Money => $carry->plus($row['rate']), $zero);
        $roomsLine = $nightlyTotal->multipliedBy($request->rooms);
        $lines[] = $this->line('room', __('Room'), $request->rooms * $nights, Money::ofMinor(intdiv($nightlyTotal->getMinorAmount()->toInt(), $nights), $currency), $roomsLine);
        $roomCharges = $roomCharges->plus($roomsLine);

        $extraAdults = 0;
        $extraChildren = 0;
        $breakfastGuests = 0;

        foreach ($rooms as $room) {
            $chargeable = $room['adults'] + $room['adult_children'] + $room['rate_children'];
            $beyond = max(0, $chargeable - $category->standard_occupancy);
            // Extra persons are the last in the order: rate children first, then adults.
            $extraRateChildren = min($beyond, $room['rate_children']);
            $extraAdults += $beyond - $extraRateChildren;
            $extraChildren += $extraRateChildren;
            $breakfastGuests += $room['adults'] + $room['adult_children'] + $room['rate_children'];
        }

        if ($extraAdults > 0) {
            $unit = Money::ofMinor($rate->extra_adult_minor, $currency);
            $amount = $unit->multipliedBy($extraAdults * $nights);
            $lines[] = $this->line('extra_adult', __('Extra adult'), $extraAdults * $nights, $unit, $amount);
            $roomCharges = $roomCharges->plus($amount);
        }

        if ($extraChildren > 0) {
            $unit = Money::ofMinor($rate->child_minor, $currency);
            $amount = $unit->multipliedBy($extraChildren * $nights);
            $lines[] = $this->line('child', __('Child'), $extraChildren * $nights, $unit, $amount);
            $roomCharges = $roomCharges->plus($amount);
        }

        if ($request->extraBed) {
            if (! $category->extra_bed_allowed) {
                $issues[] = new QuoteIssue('extra_bed_unavailable', __('An extra bed is not available in this room category.'));
            } else {
                $unit = Money::ofMinor($rate->extra_bed_minor, $currency);
                $amount = $unit->multipliedBy($request->rooms * $nights);
                $lines[] = $this->line('extra_bed', __('Extra bed'), $request->rooms * $nights, $unit, $amount);
                $roomCharges = $roomCharges->plus($amount);
            }
        }

        $breakfastCharges = $zero;

        if ($request->breakfast) {
            match ($rate->breakfast_mode) {
                BreakfastMode::None => $issues[] = new QuoteIssue('breakfast_unavailable', __('Breakfast is not offered with this room category.')),
                BreakfastMode::Included => $lines[] = $this->line('breakfast_included', __('Breakfast (included)'), $breakfastGuests * $nights, $zero, $zero),
                BreakfastMode::Optional => null,
            };

            if ($rate->breakfast_mode === BreakfastMode::Optional) {
                $unit = Money::ofMinor($rate->breakfast_minor, $currency);
                $breakfastCharges = $unit->multipliedBy($breakfastGuests * $nights);
                $lines[] = $this->line('breakfast', __('Breakfast'), $breakfastGuests * $nights, $unit, $breakfastCharges);
            }
        }

        // Taxes.
        $taxRates = TaxRate::query()->get();
        $guests = $request->adults + count($request->childAges);
        $roomTaxes = $this->taxes->calculate($taxRates, $roomCharges, TaxScope::Rooms, $request->checkIn, $nights, max(1, $guests));
        $breakfastTaxes = $breakfastCharges->isZero()
            ? null
            : $this->taxes->calculate($taxRates, $breakfastCharges, TaxScope::FoodBeverage, $request->checkIn, $nights, max(1, $guests));

        $subtotal = $roomCharges->plus($breakfastCharges);
        $total = $roomTaxes->total->plus($breakfastTaxes === null ? $zero : $breakfastTaxes->total);

        return new Quote($request, $nightRows, $lines, $subtotal, $roomTaxes, $breakfastTaxes, $total, $issues);
    }

    /**
     * @return list<array{adults: int, adult_children: int, rate_children: int, free_children: int}>
     */
    private function distribute(StayRequest $request, ChildAgeBands $bands): array
    {
        $roomCount = max(1, $request->rooms);
        $rooms = array_fill(0, $roomCount, ['adults' => 0, 'adult_children' => 0, 'rate_children' => 0, 'free_children' => 0]);

        for ($i = 0; $i < $request->adults; $i++) {
            $rooms[$i % $roomCount]['adults']++;
        }

        $children = array_map(fn (?int $age): ChildCharge => $bands->chargeFor($age), $request->childAges);
        $order = [ChildCharge::Adult, ChildCharge::ChildRate, ChildCharge::Free];
        usort($children, fn (ChildCharge $a, ChildCharge $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        foreach ($children as $i => $charge) {
            // Children go to the least occupied room first.
            usort($rooms, fn (array $a, array $b): int => array_sum($a) <=> array_sum($b));
            $key = match ($charge) {
                ChildCharge::Adult => 'adult_children',
                ChildCharge::ChildRate => 'rate_children',
                ChildCharge::Free => 'free_children',
            };
            $rooms[0][$key]++;
        }

        /** @var list<array{adults: int, adult_children: int, rate_children: int, free_children: int}> $rooms */
        return $rooms;
    }

    /**
     * @param  list<array{adults: int, adult_children: int, rate_children: int, free_children: int}>  $rooms
     * @return list<QuoteIssue>
     */
    private function occupancyIssues(StayRequest $request, RoomCategory $category, CategoryRate $rate, array $rooms): array
    {
        if ($request->rooms < 1) {
            return [new QuoteIssue('invalid_rooms', __('At least one room is needed.'))];
        }

        if ($request->adults < $request->rooms) {
            return [new QuoteIssue('adult_per_room', __('Each room needs at least one adult.'))];
        }

        foreach ($rooms as $room) {
            $children = $room['adult_children'] + $room['rate_children'] + $room['free_children'];
            $total = $room['adults'] + $children;
            $capacity = $category->max_occupancy + ($request->extraBed && $category->extra_bed_allowed ? 1 : 0);

            if ($room['adults'] > $category->max_adults || $children > $category->max_children || $total > $capacity) {
                return [new QuoteIssue('too_many_guests', __('This room category takes at most :adults adults, :children children and :total guests per room.', [
                    'adults' => $category->max_adults,
                    'children' => $category->max_children,
                    'total' => $category->max_occupancy,
                ]), ['max_adults' => $category->max_adults, 'max_children' => $category->max_children, 'max_occupancy' => $category->max_occupancy])];
            }
        }

        return [];
    }

    /**
     * @return array{code: string, label: string, quantity: int, unit: Money, amount: Money}
     */
    private function line(string $code, string $label, int $quantity, Money $unit, Money $amount): array
    {
        return ['code' => $code, 'label' => $label, 'quantity' => $quantity, 'unit' => $unit, 'amount' => $amount];
    }

    /**
     * @param  list<QuoteIssue>  $issues
     */
    private function empty(StayRequest $request, Money $zero, array $issues): Quote
    {
        return new Quote($request, [], [], $zero, null, null, $zero, $issues);
    }
}
