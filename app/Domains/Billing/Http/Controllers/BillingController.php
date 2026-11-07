<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\BillingOverview;
use App\Domains\Billing\Models\CreditPack;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Brick\Money\Money;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hotel → Billing: plan, credits and usage.
 */
final class BillingController extends Controller
{
    public function __invoke(TenantContext $context, BillingOverview $overview): Response
    {
        $locale = app()->getLocale();
        $data = $overview->build($locale);
        $currency = $data['subscription']['currency'] ?? $context->hotel()->currency;

        return Inertia::render('hotel/billing', [
            ...$data,
            'plans' => BillingOverview::plans($locale, $currency),
            'packsForSale' => CreditPack::query()->where('active', true)->orderBy('kind')->orderBy('credits')->get()
                ->filter(fn (CreditPack $p): bool => isset($p->prices[$currency]))
                ->map(fn (CreditPack $p): array => ['id' => $p->id, 'name' => $p->localizedName($locale), 'kind' => $p->kind->label(), 'credits' => $p->credits, 'price' => MoneyFormatter::format(Money::ofMinor((int) $p->prices[$currency], $currency), $locale)])
                ->values(),
        ]);
    }
}
