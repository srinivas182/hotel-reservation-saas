<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Exceptions\PlanChangeNotPossible;
use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionChange;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Tenancy\Models\HotelMembership;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A hotel's subscription: trial, periods, plan changes and the credits each
 * period brings. Amounts to invoice are recorded as changes (invoiced in S13.2).
 */
final class Subscriptions
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Credits $credits,
    ) {}

    public function start(Plan $plan, string $interval, string $currency, bool $trial, ?User $by = null): Subscription
    {
        if (Subscription::query()->exists()) {
            throw new PlanChangeNotPossible('exists', (string) __('This hotel already has a subscription.'));
        }

        $price = $plan->price($currency, $interval) ?? throw new PlanChangeNotPossible('price', (string) __('The plan has no :interval price in :currency.', ['interval' => $interval, 'currency' => $currency]));
        $now = now()->toImmutable();
        $end = $trial ? $now->addDays($plan->trial_days) : $this->periodEnd($now, $interval);

        $subscription = DB::transaction(function () use ($plan, $interval, $currency, $price, $trial, $now, $end, $by): Subscription {
            $subscription = Subscription::query()->create([
                'plan_id' => $plan->id, 'status' => $trial ? 'trialing' : 'active', 'interval' => $interval, 'currency' => $currency,
                'price_minor' => $price, 'period_start' => $now, 'period_end' => $end, 'trial_ends_at' => $trial ? $end : null,
            ]);
            $this->grantPeriod($subscription, $plan, $trial ? 'trial' : 'allowance');
            $this->log($subscription, $trial ? 'trial_started' : 'started', null, $plan, $trial ? 0 : $price, $by);

            return $subscription;
        });

        Audit::record('billing.subscription_started', 'Subscription started', subject: $this->context->hotel(), causer: $by, properties: ['plan' => $plan->code, 'trial' => $trial, 'interval' => $interval, 'currency' => $currency]);

        return $subscription;
    }

    /**
     * Upgrade now (pro rata, extra credits for the rest of the period), or
     * downgrade at the end of the period once the hotel fits the plan.
     */
    public function changePlan(Subscription $subscription, Plan $to, User $by): Subscription
    {
        $from = $subscription->plan ?? throw new PlanChangeNotPossible('plan', 'No plan.');
        $newPrice = $to->price($subscription->currency, $subscription->interval)
            ?? throw new PlanChangeNotPossible('price', (string) __('The plan has no :interval price in :currency.', ['interval' => $subscription->interval, 'currency' => $subscription->currency]));

        if ($to->id === $from->id) {
            $subscription->forceFill(['pending_plan_id' => null])->save();

            return $subscription;
        }

        if ($newPrice > $subscription->price_minor) {
            DB::transaction(function () use ($subscription, $from, $to, $newPrice, $by): void {
                $fraction = $this->remainingFraction($subscription);
                $amount = $subscription->status === 'trialing' ? 0 : (int) round(($newPrice - $subscription->price_minor) * $fraction);

                foreach (CreditKind::cases() as $kind) {
                    $extra = (int) floor(max(0, $to->credits($kind) - $from->credits($kind)) * $fraction);
                    if ($extra > 0) {
                        $this->credits->grant($kind, $subscription->status === 'trialing' ? 'trial' : 'allowance', $extra, $subscription->period_end, 'Upgrade to '.$to->code, null, $by);
                    }
                }

                $subscription->forceFill(['plan_id' => $to->id, 'price_minor' => $newPrice, 'pending_plan_id' => null])->save();
                $this->log($subscription, 'upgraded', $from, $to, $amount, $by);
            });
        } else {
            $problems = $this->doesNotFit($to);
            if ($problems !== []) {
                throw new PlanChangeNotPossible('does_not_fit', (string) __('Before moving to :plan: :problems', ['plan' => $to->localizedName(app()->getLocale()), 'problems' => implode(' ', $problems)]));
            }

            $subscription->forceFill(['pending_plan_id' => $to->id])->save();
            $this->log($subscription, 'downgrade_scheduled', $from, $to, 0, $by);
        }

        Audit::record('billing.plan_changed', 'Plan changed', subject: $this->context->hotel(), causer: $by, properties: ['from' => $from->code, 'to' => $to->code, 'pending' => $subscription->pending_plan_id !== null]);

        return $subscription->fresh(['plan', 'pendingPlan']) ?? $subscription;
    }

    public function extendTrial(Subscription $subscription, int $days, User $by): void
    {
        if ($subscription->status !== 'trialing') {
            throw new PlanChangeNotPossible('status', (string) __('Only a trial can be extended.'));
        }

        $end = $subscription->period_end->addDays($days);
        $subscription->forceFill(['period_end' => $end, 'trial_ends_at' => $end])->save();
        Models\CreditBucket::query()->where('source', 'trial')->update(['expires_at' => $end]);
        $this->log($subscription, 'trial_extended', null, null, 0, $by);
        Audit::record('billing.trial_extended', 'Trial extended', subject: $this->context->hotel(), causer: $by, properties: ['days' => $days]);
    }

    /**
     * A credit pack sold to the hotel (paid outside or invoiced in S13.2).
     */
    public function addPack(CreditPack $pack, ?User $by): ?SubscriptionChange
    {
        $subscription = Subscription::query()->first();
        $this->credits->grant($pack->kind, 'pack', $pack->credits, now()->addMonths(max(1, (int) $pack->valid_months))->toImmutable(), $pack->code, $pack->id, $by);
        $change = $subscription === null ? null : $this->log($subscription, 'pack:'.$pack->code, null, null, (int) ($pack->prices[$subscription->currency] ?? 0), $by);

        Audit::record('billing.pack_added', 'Credit pack added', subject: $this->context->hotel(), causer: $by, properties: ['pack' => $pack->code]);

        return $change;
    }

    /**
     * Start the next period of the current hotel's subscription when due:
     * the trial ends, a scheduled downgrade applies, and the month's credits
     * are granted.
     */
    public function renewIfDue(): bool
    {
        $subscription = Subscription::query()->with(['plan', 'pendingPlan'])->where('status', '!=', 'cancelled')->where('period_end', '<=', now())->first();

        if ($subscription === null) {
            return false;
        }

        DB::transaction(function () use ($subscription): void {
            $plan = $subscription->pendingPlan ?? $subscription->plan;
            $from = $subscription->plan;
            $price = $plan?->price($subscription->currency, $subscription->interval) ?? $subscription->price_minor;
            $start = $subscription->period_end;
            $wasTrial = $subscription->status === 'trialing';

            $subscription->forceFill([
                'plan_id' => $plan->id ?? $subscription->plan_id, 'pending_plan_id' => null, 'status' => 'active',
                'price_minor' => $subscription->pending_plan_id !== null ? $price : $subscription->price_minor,
                'period_start' => $start, 'period_end' => $this->periodEnd($start, $subscription->interval), 'warnings' => [],
            ])->save();

            if ($plan !== null) {
                $this->grantPeriod($subscription, $plan, 'allowance');
            }

            if ($from !== null && $plan !== null && $from->id !== $plan->id) {
                $this->log($subscription, 'downgrade_applied', $from, $plan, 0, null);
            }

            $this->log($subscription, $wasTrial ? 'trial_converted' : 'renewed', null, $plan, $subscription->price_minor, null);
        });

        return true;
    }

    /**
     * What the hotel uses that the plan does not include.
     *
     * @return list<string>
     */
    public function doesNotFit(Plan $plan): array
    {
        $problems = [];
        $staff = HotelMembership::query()->where('hotel_id', $this->context->id())->count();

        if ($plan->staff_limit !== null && $staff > $plan->staff_limit) {
            $problems[] = (string) __('remove :count staff account(s) (the plan allows :limit).', ['count' => $staff - $plan->staff_limit, 'limit' => $plan->staff_limit]);
        }

        if (! in_array(Module::Restaurant->value, $plan->modules, true) && Outlet::query()->where('active', true)->exists()) {
            $problems[] = (string) __('switch off your restaurants and bars.');
        }

        if (! in_array(Module::Events->value, $plan->modules, true) && \App\Domains\Events\Models\Event::query()->whereIn('status', ['option', 'confirmed'])->where('date', '>=', now()->toDateString())->exists()) {
            $problems[] = (string) __('finish or cancel your upcoming events.');
        }

        return $problems;
    }

    private function grantPeriod(Subscription $subscription, Plan $plan, string $source): void
    {
        foreach (CreditKind::cases() as $kind) {
            if ($plan->credits($kind) > 0) {
                $this->credits->grant($kind, $source, $plan->credits($kind), $subscription->period_end, null);
            }
        }
    }

    private function periodEnd(CarbonImmutable $start, string $interval): CarbonImmutable
    {
        return $interval === 'yearly' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
    }

    private function remainingFraction(Subscription $subscription): float
    {
        $total = max(1, $subscription->period_start->diffInSeconds($subscription->period_end));

        return max(0.0, min(1.0, now()->diffInSeconds($subscription->period_end, false) / $total));
    }

    private function log(Subscription $subscription, string $kind, ?Plan $from, ?Plan $to, int $amount, ?User $by): SubscriptionChange
    {
        return SubscriptionChange::query()->create([
            'subscription_id' => $subscription->id, 'kind' => $kind, 'from_plan_id' => $from?->id, 'to_plan_id' => $to?->id,
            'amount_minor' => $amount, 'currency' => $subscription->currency, 'created_by' => $by?->getKey(),
        ]);
    }
}
