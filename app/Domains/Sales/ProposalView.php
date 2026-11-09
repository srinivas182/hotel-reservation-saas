<?php

declare(strict_types=1);

namespace App\Domains\Sales;

use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Sales\Models\Proposal;
use App\Domains\Site\Models\BrandSettings;
use Brick\Money\Money;

/**
 * What a proposal shows (web page and PDF).
 */
final class ProposalView
{
    /**
     * @return array<string, mixed>
     */
    public static function data(Proposal $p): array
    {
        $p->loadMissing(['lead', 'plan']);
        $money = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $p->currency), $p->locale);
        $roi = Roi::compute($p->roi, $p->monthlyCost());
        $entity = BillingEntity::default();

        return [
            'p' => $p,
            'locale' => $p->locale,
            'brand' => BrandSettings::cached() ?? ['name' => config('app.name'), 'color' => '#4f46e5', 'support_email' => null, 'support_whatsapp' => null, 'logo' => null, 'tagline' => []],
            'plan' => $p->plan->localizedName($p->locale),
            'modules' => $p->plan->modules,
            'price' => $money($p->price()),
            'base' => $money((int) ($p->plan->price($p->currency, $p->cycle) ?? 0)),
            'roi' => [...$roi, 'revenue_text' => $money($roi['revenue']), 'cost_text' => $money($roi['cost']), 'net_text' => $money($roi['net']), 'night_price_text' => $money((int) ($p->roi['night_price'] ?? Roi::DEFAULTS['night_price']))],
            'inputs' => [...Roi::DEFAULTS, ...$p->roi],
            'includesTax' => $entity?->prices_include_tax === true,
            'issuer' => $entity?->name,
            'url' => url('/proposal/'.$p->token),
        ];
    }
}
