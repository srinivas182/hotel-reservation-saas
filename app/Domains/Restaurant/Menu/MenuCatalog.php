<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Menu;

use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Finance\Taxes\TaxCalculator;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Finance\Taxes\TaxScope;
use App\Domains\Restaurant\Models\Minutes;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\Models\ServicePeriod;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The menu as guests see it: active dishes, available today, served at the
 * current service, with the price the guest pays (food and drink taxes
 * applied as the hotel set them up).
 */
final class MenuCatalog
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TaxCalculator $taxes,
    ) {}

    /**
     * @return array{period: string|null, sections: list<array{name: string, items: list<array{id: int, name: string, description: string|null, price: Money, price_text: string, labels: list<string>, allergens: list<string>, photo: bool}>}>}
     */
    public function forGuests(Outlet $outlet, string $locale, ?CarbonImmutable $at = null, bool $allServices = false): array
    {
        $hotel = $this->context->hotel();
        $at ??= CarbonImmutable::now($hotel->timezone);
        $period = $allServices ? null : $this->currentPeriod($outlet, $at);
        $rates = TaxRate::query()->where('active', true)->get();
        $sections = [];

        $all = MenuSection::query()->with(['items' => fn ($q) => $q->where('active', true)])
            ->where('outlet_id', $outlet->id)->orderBy('sort_order')->orderBy('id')->get();

        foreach ($all as $section) {
            $items = [];

            foreach ($section->items as $item) {
                if (! $item->availableToday($hotel) || ! $item->servedAt($period?->name)) {
                    continue;
                }

                $price = $this->priceToPay($item, $rates->values()->all(), $at);
                $items[] = [
                    'id' => $item->id,
                    'name' => $item->localizedName($locale, $hotel->default_locale),
                    'description' => $item->localizedDescription($locale, $hotel->default_locale),
                    'price' => $price,
                    'price_text' => MoneyFormatter::format($price, $locale),
                    'labels' => array_map(fn (string $l): string => MenuLabel::label($l), $item->labels ?? []),
                    'allergens' => array_map(fn (string $a): string => MenuLabel::label($a), $item->allergens ?? []),
                    'photo' => $item->photo_path !== null,
                ];
            }

            if ($items !== []) {
                $sections[] = ['name' => $section->localizedName($locale, $hotel->default_locale), 'items' => $items];
            }
        }

        return ['period' => $period?->localizedName($locale, $hotel->default_locale), 'sections' => $sections];
    }

    /**
     * The price the guest pays for one item.
     *
     * @param  iterable<TaxRate>  $rates
     */
    public function priceToPay(MenuItem $item, iterable $rates, CarbonImmutable $at): Money
    {
        $price = Money::ofMinor($item->price_minor, $this->context->hotel()->currency);

        return $this->taxes->calculate($rates, $price, TaxScope::FoodBeverage, $at)->total;
    }

    /**
     * The service now, or the next one today; null when none is left today.
     */
    public function currentPeriod(Outlet $outlet, CarbonImmutable $at): ?ServicePeriod
    {
        $now = Minutes::of($at->format('H:i'));
        $periods = ServicePeriod::query()->where('outlet_id', $outlet->id)->where('weekday', $at->dayOfWeekIso)->orderBy('starts_at')->get();

        return $periods->first(fn (ServicePeriod $p): bool => Minutes::of($p->starts_at) <= $now && $now < Minutes::of($p->ends_at))
            ?? $periods->first(fn (ServicePeriod $p): bool => Minutes::of($p->starts_at) > $now);
    }

    public function today(): CarbonImmutable
    {
        return HotelClock::today($this->context->hotel());
    }
}
