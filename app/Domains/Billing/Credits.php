<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Models\BillingSettings;
use App\Domains\Billing\Models\CreditBucket;
use App\Domains\Billing\Models\Subscription;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AI and email credits of the current hotel, in hundredths of a credit.
 * Hotels without a subscription are not limited (set-up and legacy hotels;
 * the platform assigns a plan).
 */
final class Credits
{
    public const UNIT = 100;

    public function __construct(private readonly TenantContext $context) {}

    public function limited(): bool
    {
        return Subscription::query()->exists();
    }

    /**
     * @return array{granted: int, used: int, left: int}
     */
    public function summary(CreditKind $kind): array
    {
        $buckets = CreditBucket::query()->valid()->where('kind', $kind->value)->get(['granted', 'used']);
        $granted = (int) $buckets->sum('granted');
        $used = (int) $buckets->sum('used');

        return ['granted' => $granted, 'used' => $used, 'left' => $granted - $used];
    }

    /**
     * Whether the hotel may use more credits (a small buffer is allowed so a
     * conversation is not cut mid-reply).
     */
    public function canUse(CreditKind $kind): bool
    {
        if (! $this->limited()) {
            return true;
        }

        $summary = $this->summary($kind);
        $buffer = (int) floor($summary['granted'] * BillingSettings::current()->buffer_percent / 100);

        return $summary['left'] > -$buffer;
    }

    /**
     * Use credits: the bucket expiring first is used first (the month's
     * allowance, then packs). Beyond what is granted, the last bucket goes
     * negative (the buffer).
     */
    public function consume(CreditKind $kind, int $hundredths): void
    {
        if ($hundredths <= 0 || ! $this->limited()) {
            return;
        }

        DB::transaction(function () use ($kind, $hundredths): void {
            $buckets = CreditBucket::query()->valid()->where('kind', $kind->value)
                ->orderByRaw('case when expires_at is null then 1 else 0 end')->orderBy('expires_at')->orderBy('id')
                ->lockForUpdate()->get();
            $left = $hundredths;

            foreach ($buckets as $bucket) {
                $take = min($left, max(0, $bucket->granted - $bucket->used));
                if ($take > 0) {
                    $bucket->forceFill(['used' => $bucket->used + $take])->save();
                    $left -= $take;
                }
                if ($left === 0) {
                    break;
                }
            }

            if ($left > 0) {
                $last = $buckets->last() ?? CreditBucket::query()->create(['kind' => $kind, 'source' => 'adjustment', 'granted' => 0, 'starts_at' => now(), 'reason' => 'Used without credits']);
                $last->forceFill(['used' => $last->used + $left])->save();
            }
        });

        $this->warnIfLow($kind);
    }

    public function grant(CreditKind $kind, string $source, int $credits, ?\Carbon\CarbonImmutable $expiresAt, ?string $reason = null, ?int $packId = null, ?User $by = null): CreditBucket
    {
        $bucket = CreditBucket::query()->create([
            'kind' => $kind, 'source' => $source, 'granted' => $credits * self::UNIT, 'starts_at' => now(),
            'expires_at' => $expiresAt, 'credit_pack_id' => $packId, 'reason' => $reason, 'created_by' => $by?->getKey(),
        ]);

        // New credits re-arm the warnings.
        $subscription = Subscription::query()->first();
        if ($subscription !== null) {
            $warnings = $subscription->warnings ?? [];
            unset($warnings[$kind->value.'_80'], $warnings[$kind->value.'_100']);
            $subscription->forceFill(['warnings' => $warnings])->save();
        }

        return $bucket;
    }

    /**
     * A platform adjustment (positive or negative), with a reason.
     */
    public function adjust(CreditKind $kind, int $credits, string $reason, User $by): void
    {
        if ($credits > 0) {
            $subscription = Subscription::query()->first();
            $this->grant($kind, 'adjustment', $credits, $subscription?->period_end, $reason, null, $by);
        } else {
            $this->consume($kind, -$credits * self::UNIT);
        }

        Audit::record('billing.credits_adjusted', 'Credits adjusted', subject: $this->context->hotel(), causer: $by, properties: ['kind' => $kind->value, 'credits' => $credits, 'reason' => $reason]);
    }

    /**
     * Tell the owner once at 80 % and once at 100 % of the credits granted.
     */
    private function warnIfLow(CreditKind $kind): void
    {
        $subscription = Subscription::query()->first();
        $summary = $this->summary($kind);

        if ($subscription === null || $summary['granted'] <= 0) {
            return;
        }

        $ratio = $summary['used'] / $summary['granted'];
        $warnings = $subscription->warnings ?? [];

        foreach ([100, 80] as $level) {
            $key = $kind->value.'_'.$level;
            if ($ratio * 100 >= $level && ! isset($warnings[$key])) {
                $warnings[$key] = now()->toIso8601String();
                if ($level === 100) {
                    $warnings[$kind->value.'_80'] ??= now()->toIso8601String();
                }
                $subscription->forceFill(['warnings' => $warnings])->save();

                $text = $level === 100
                    ? __(':kind used up. :what until the next period or a credit pack.', ['kind' => $kind->label(), 'what' => $kind === CreditKind::Ai ? __('The assistant passes guests to your team') : __('Guest emails are not sent')])
                    : __(':percent% of your :kind used this period.', ['percent' => 80, 'kind' => mb_strtolower($kind->label())]);
                app(StaffAlerts::class)->notify(AlertKind::CreditsLow, (string) $text, $subscription->id.':'.$key);
                Audit::record('billing.credits_warning', 'Credits warning sent', subject: $this->context->hotel(), properties: ['kind' => $kind->value, 'level' => $level]);

                break;
            }
        }
    }
}
