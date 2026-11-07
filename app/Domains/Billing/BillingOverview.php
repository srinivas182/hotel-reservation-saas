<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Billing\Models\CreditBucket;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Messaging\Usage\UsageRecord;
use App\Domains\Tenancy\Models\HotelMembership;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;

/**
 * The current hotel's plan, period, credits and usage, for the billing
 * screens (hotel and platform).
 */
final class BillingOverview
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Credits $credits,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $locale): array
    {
        $hotel = $this->context->hotel();
        $subscription = Subscription::query()->with(['plan', 'pendingPlan'])->first();
        $money = fn (int $minor, string $currency): string => MoneyFormatter::format(Money::ofMinor($minor, $currency), $locale);
        $from = $subscription->period_start ?? now()->startOfMonth();

        return [
            'subscription' => $subscription === null ? null : [
                'plan' => $subscription->plan?->localizedName($locale),
                'plan_id' => $subscription->plan_id,
                'status' => $subscription->status,
                'interval' => $subscription->interval,
                'price' => $money($subscription->price_minor, $subscription->currency),
                'currency' => $subscription->currency,
                'period_start' => $subscription->period_start->setTimezone($hotel->timezone)->toDateString(),
                'period_end' => $subscription->period_end->setTimezone($hotel->timezone)->toDateString(),
                'trial_ends_at' => $subscription->trial_ends_at?->setTimezone($hotel->timezone)->toDateString(),
                'pending_plan' => $subscription->pendingPlan?->localizedName($locale),
            ],
            'credits' => collect(CreditKind::cases())->map(function (CreditKind $kind): array {
                $summary = $this->credits->summary($kind);

                return [
                    'kind' => $kind->value,
                    'label' => $kind->label(),
                    'granted' => intdiv($summary['granted'], Credits::UNIT),
                    'used' => round($summary['used'] / Credits::UNIT, 1),
                    'left' => round($summary['left'] / Credits::UNIT, 1),
                    'percent' => $summary['granted'] > 0 ? min(100, (int) round(100 * $summary['used'] / $summary['granted'])) : 0,
                    'available' => $this->credits->canUse($kind),
                ];
            })->values()->all(),
            'packs' => CreditBucket::query()->valid()->where('source', 'pack')->orderBy('expires_at')->get()->map(fn (CreditBucket $b): array => [
                'kind' => $b->kind->label(), 'left' => round(($b->granted - $b->used) / Credits::UNIT, 1), 'expires' => $b->expires_at?->toDateString(),
            ])->values()->all(),
            'staff' => ['count' => HotelMembership::query()->where('hotel_id', $hotel->getKey())->count(), 'limit' => $this->entitlements->staffLimit()],
            'modules' => array_map(fn (Module $m): array => ['label' => $m->label(), 'included' => $this->entitlements->has($m)], Module::cases()),
            'whatsapp' => [
                'service' => (int) UsageRecord::query()->where('meter', 'whatsapp.service')->where('occurred_at', '>=', $from)->sum('quantity'),
                'templates' => (int) UsageRecord::query()->where('meter', 'like', 'whatsapp.template.%')->where('occurred_at', '>=', $from)->sum('quantity'),
            ],
            'limited' => $this->credits->limited(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function plans(string $locale, string $currency): array
    {
        return Plan::query()->where('active', true)->orderBy('sort_order')->get()->map(fn (Plan $p): array => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->localizedName($locale),
            'monthly' => $p->price($currency, 'monthly') === null ? null : MoneyFormatter::format(Money::ofMinor((int) $p->price($currency, 'monthly'), $currency), $locale),
            'ai_credits' => $p->ai_credits, 'email_credits' => $p->email_credits, 'staff_limit' => $p->staff_limit,
            'modules' => array_map(fn (string $m): string => Module::from($m)->label(), $p->modules),
        ])->values()->all();
    }
}
