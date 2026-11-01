<?php

declare(strict_types=1);

namespace App\Domains\Rates\Http\Controllers;

use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Finance\Taxes\TaxBreakdown;
use App\Domains\Finance\Taxes\TaxLine;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\BreakfastMode;
use App\Domains\Rates\ChildCharge;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rates\Models\RateOverride;
use App\Domains\Rates\Quote\QuoteIssue;
use App\Domains\Rates\Quote\QuoteService;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RatesController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request, QuoteService $quotes): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $settings = HotelSettings::current();
        $digits = Money::zero($hotel->currency)->getCurrency()->getDefaultFractionDigits();
        $major = fn (int $minor): string => (string) Money::ofMinor($minor, $hotel->currency)->getAmount();

        $categories = RoomCategory::query()->orderBy('sort_order')->orderBy('code')->get();
        $rates = CategoryRate::query()->get()->keyBy('room_category_id');

        return Inertia::render('hotel/rates', [
            'currency' => $hotel->currency,
            'minorDigits' => $digits,
            'categories' => $categories->map(function (RoomCategory $category) use ($rates, $major, $locale): array {
                /** @var CategoryRate|null $rate */
                $rate = $rates->get($category->id);

                return [
                    'id' => $category->id,
                    'code' => $category->code,
                    'name' => $category->localizedName($locale, $locale),
                    'active' => $category->active,
                    'extra_bed_allowed' => $category->extra_bed_allowed,
                    'standard_occupancy' => $category->standard_occupancy,
                    'rate' => $rate === null ? null : [
                        'base' => $major($rate->base_minor),
                        'extra_adult' => $major($rate->extra_adult_minor),
                        'child' => $major($rate->child_minor),
                        'extra_bed' => $major($rate->extra_bed_minor),
                        'breakfast_mode' => $rate->breakfast_mode->value,
                        'breakfast' => $major($rate->breakfast_minor),
                        'min_nights' => $rate->min_nights,
                    ],
                ];
            })->values(),
            'overrides' => RateOverride::query()
                ->orderBy('from_date')
                ->get()
                ->map(fn (RateOverride $override): array => [
                    'id' => $override->id,
                    'room_category_id' => $override->room_category_id,
                    'from_date' => $override->from_date->toDateString(),
                    'to_date' => $override->to_date->toDateString(),
                    'weekdays' => $override->weekdays ?? [],
                    'rate' => $override->rate_minor === null ? null : $major($override->rate_minor),
                    'min_nights' => $override->min_nights,
                    'stop_sell' => $override->stop_sell,
                    'closed_to_arrival' => $override->closed_to_arrival,
                    'label' => $override->label,
                    'overlaps' => $this->overlaps($override),
                ])->values(),
            'settings' => [
                'child_age_bands' => $settings->child_age_bands ?? [],
                'hold_minutes' => $settings->hold_minutes,
            ],
            'breakfastModes' => collect(BreakfastMode::cases())->map(fn (BreakfastMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()])->values(),
            'childCharges' => collect(ChildCharge::cases())->map(fn (ChildCharge $charge): array => ['value' => $charge->value, 'label' => $charge->label()])->values(),
            'quote' => Inertia::optional(fn (): array => $this->quote($request, $quotes)),
            'canManage' => $request->user()?->can('rates.manage') === true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function quote(Request $request, QuoteService $quotes): array
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;

        $input = $request->validate([
            'category' => ['required', 'string'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d'],
            'adults' => ['required', 'integer', 'min:1', 'max:40'],
            'children' => ['nullable', 'string', 'max:100'],
            'rooms' => ['required', 'integer', 'min:1', 'max:20'],
            'breakfast' => ['nullable', 'boolean'],
            'extra_bed' => ['nullable', 'boolean'],
        ]);

        $ages = array_values(array_filter(array_map('trim', explode(',', (string) ($input['children'] ?? ''))), fn (string $age): bool => $age !== ''));

        $quote = $quotes->quote(new StayRequest(
            categoryId: (string) $input['category'],
            checkIn: CarbonImmutable::parse((string) $input['check_in'], $hotel->timezone),
            checkOut: CarbonImmutable::parse((string) $input['check_out'], $hotel->timezone),
            adults: (int) $input['adults'],
            childAges: array_map(fn (string $age): int => max(0, min(17, (int) $age)), $ages),
            rooms: (int) $input['rooms'],
            breakfast: (bool) ($input['breakfast'] ?? false),
            extraBed: (bool) ($input['extra_bed'] ?? false),
        ));

        $format = fn (Money $money): string => MoneyFormatter::format($money, $locale);
        $taxLines = fn (?TaxBreakdown $breakdown): array => $breakdown === null ? [] : array_map(fn (TaxLine $line): array => [
            'name' => $line->rate->localizedName($locale, $locale),
            'amount' => $format($line->amount),
            'inclusive' => $line->inclusive,
        ], $breakdown->lines);

        return [
            'bookable' => $quote->bookable(),
            'issues' => array_map(fn (QuoteIssue $issue): string => $issue->message, $quote->issues),
            'nights' => array_map(fn (array $night): array => [
                'date' => $night['date'],
                'rate' => $format($night['rate']),
                'label' => $night['label'],
                'available' => $night['available'],
            ], $quote->nights),
            'lines' => array_map(fn (array $line): array => [
                'label' => $line['label'],
                'quantity' => $line['quantity'],
                'amount' => $format($line['amount']),
            ], $quote->lines),
            'subtotal' => $format($quote->subtotal),
            'taxes' => [...$taxLines($quote->roomTaxes), ...$taxLines($quote->breakfastTaxes)],
            'total' => $format($quote->total),
        ];
    }

    private function overlaps(RateOverride $override): bool
    {
        return RateOverride::query()
            ->whereKeyNot($override->getKey())
            ->where('from_date', '<=', $override->to_date->toDateString())
            ->where('to_date', '>=', $override->from_date->toDateString())
            ->where(fn ($query) => $override->room_category_id === null
                ? $query
                : $query->whereNull('room_category_id')->orWhere('room_category_id', $override->room_category_id))
            ->exists();
    }
}
