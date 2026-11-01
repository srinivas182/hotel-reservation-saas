<?php

declare(strict_types=1);

namespace App\Domains\Availability\Http\Controllers;

use App\Domains\Availability\AvailabilityService;
use App\Domains\Availability\Models\InventoryHold;
use App\Domains\Rates\NightlyRates;
use App\Domains\Rates\Quote\QuotePresenter;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Availability calendar: room categories against nights.
 */
final class CalendarController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request, AvailabilityService $availability, NightlyRates $nightlyRates, QuotePresenter $quotes): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $today = HotelClock::today($hotel);

        $input = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'in:14,30'],
        ]);

        $start = isset($input['start']) ? CarbonImmutable::parse((string) $input['start']) : $today;
        $days = (int) ($input['days'] ?? 14);
        $end = $start->addDays($days - 1);

        $categories = RoomCategory::query()->where('active', true)->orderBy('sort_order')->orderBy('code')->get();
        $ids = array_values(array_map('strval', $categories->pluck('id')->all()));

        $grid = $ids === [] ? [] : $availability->forRange($ids, $start, $end);
        $prices = $ids === [] ? [] : $nightlyRates->forRange($ids, $start, $end);
        $digits = Money::zero($hotel->currency)->getCurrency()->getDefaultFractionDigits();

        $dates = [];
        foreach (CarbonPeriod::create($start, $end) as $night) {
            /** @var CarbonImmutable $localized */
            $localized = CarbonImmutable::instance($night)->locale($locale);
            $dates[] = [
                'date' => $night->toDateString(),
                'weekday' => $localized->isoFormat('ddd'),
                'day' => $localized->isoFormat('D MMM'),
                'weekend' => $night->isWeekend(),
                'today' => $night->toDateString() === $today->toDateString(),
            ];
        }

        $cells = [];
        foreach ($ids as $id) {
            foreach ($dates as $date) {
                $night = $grid[$id][$date['date']] ?? null;
                $price = $prices[$id][$date['date']] ?? null;
                $rateMinor = $price['rate_minor'] ?? null;

                $cells[$id][$date['date']] = [
                    'available' => ($price['stop_sell'] ?? false) ? 0 : ($night['available'] ?? 0),
                    'sellable' => $night['sellable'] ?? 0,
                    'held' => $night['held'] ?? 0,
                    'booked' => $night['booked'] ?? 0,
                    'held_back' => $night['held_back'] ?? 0,
                    'stop_sell' => ($night['stop_sell'] ?? false) || ($price['stop_sell'] ?? false),
                    'rate' => $rateMinor === null ? null : number_format($rateMinor / (10 ** $digits), $digits, ',', ' '),
                    'label' => $price['label'] ?? null,
                ];
            }
        }

        $holds = InventoryHold::query()
            ->counting()
            ->with('category')
            ->orderBy('check_in')
            ->limit(100)
            ->get()
            ->map(fn (InventoryHold $hold): array => [
                'id' => $hold->id,
                'category' => $hold->category?->localizedName($locale, $locale),
                'check_in' => $hold->check_in->toDateString(),
                'check_out' => $hold->check_out->toDateString(),
                'rooms' => $hold->rooms,
                'source' => $hold->source_type,
                'note' => $hold->note,
                'expires_at' => $hold->expires_at->setTimezone($hotel->timezone)->format('H:i'),
            ])->values();

        return Inertia::render('hotel/calendar', [
            'start' => $start->toDateString(),
            'days' => $days,
            'previous' => $start->subDays($days)->toDateString(),
            'next' => $start->addDays($days)->toDateString(),
            'today' => $today->toDateString(),
            'currency' => $hotel->currency,
            'dates' => $dates,
            'categories' => $categories->map(fn (RoomCategory $category): array => [
                'id' => $category->id,
                'code' => $category->code,
                'name' => $category->localizedName($locale, $locale),
            ])->values(),
            'cells' => $cells,
            'holds' => $holds,
            'quote' => Inertia::optional(fn (): array => $quotes->fromRequest($request)),
            'can' => [
                'manage' => $request->user()?->can('availability.manage') === true,
                'rates' => $request->user()?->can('rates.manage') === true,
            ],
        ]);
    }
}
