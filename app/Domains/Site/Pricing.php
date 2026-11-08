<?php

declare(strict_types=1);

namespace App\Domains\Site;

use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Plan;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Onboarding\Models\CountryTemplate;
use Brick\Money\Money;

/**
 * The public price list, built from Billing's plans and packs so the
 * website can never disagree with what is invoiced.
 */
final class Pricing
{
    /**
     * @return list<string> currencies at least one active plan is priced in
     */
    public static function currencies(): array
    {
        $all = Plan::query()->where('active', true)->get()->flatMap(fn (Plan $p) => array_keys($p->prices))->unique()->sort()->values()->all();

        return array_values(array_map('strval', $all));
    }

    /**
     * The visitor's currency: asked for, else from their country, else FCFA.
     */
    public static function currencyFor(?string $asked, ?string $country): string
    {
        $available = self::currencies();
        $asked = strtoupper((string) $asked);

        if (in_array($asked, $available, true)) {
            return $asked;
        }

        $fromCountry = $country === null ? null : CountryTemplate::query()->where('country_code', strtoupper($country))->value('currency');

        return in_array($fromCountry, $available, true) ? (string) $fromCountry : (in_array('XOF', $available, true) ? 'XOF' : ($available[0] ?? 'XOF'));
    }

    /**
     * @return array{currency: string, includes_tax: bool, plans: array<int, array<string, mixed>>, packs: array<int, array<string, mixed>>}
     */
    public static function build(string $currency, string $locale): array
    {
        $money = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $currency), $locale);

        $plans = Plan::query()->where('active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (Plan $p): bool => $p->price($currency, 'monthly') !== null)
            ->map(function (Plan $p) use ($currency, $locale, $money): array {
                $monthly = (int) $p->price($currency, 'monthly');
                $yearly = $p->price($currency, 'yearly');

                return [
                    'code' => $p->code,
                    'name' => $p->localizedName($locale),
                    'description' => $p->description[$locale] ?? $p->description['fr'] ?? null,
                    'monthly' => $money($monthly),
                    'yearly' => $yearly === null ? null : $money($yearly),
                    'yearly_saving' => $yearly === null || $monthly * 12 <= $yearly ? null : $money($monthly * 12 - $yearly),
                    'modules' => $p->modules,
                    'staff_limit' => $p->staff_limit,
                    'ai_credits' => $p->ai_credits,
                    'email_credits' => $p->email_credits,
                    'trial_days' => $p->trial_days,
                    'featured' => $p->is_default,
                ];
            })->values()->all();

        $packs = CreditPack::query()->where('active', true)->orderBy('kind')->orderBy('credits')->get()
            ->filter(fn (CreditPack $c): bool => isset($c->prices[$currency]))
            ->map(fn (CreditPack $c): array => ['name' => $c->localizedName($locale), 'kind' => $c->kind, 'credits' => $c->credits, 'price' => $money((int) $c->prices[$currency])])
            ->values()->all();

        return ['currency' => $currency, 'includes_tax' => BillingEntity::default()?->prices_include_tax === true, 'plans' => $plans, 'packs' => $packs];
    }
}
