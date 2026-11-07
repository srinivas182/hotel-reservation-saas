<?php

declare(strict_types=1);

namespace App\Domains\Billing\Invoices;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Mail\InvoiceMail;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Unpaid invoices: reminders 3, 7 and 10 days after the due date; reduced
 * mode after 14 days (assistant off, set-up read-only, data kept), unless
 * the platform granted a delay. Paying lifts reduced mode at once.
 */
final class Dunning
{
    public const REMINDER_DAYS = [3, 7, 10];

    public const REDUCED_AFTER_DAYS = 14;

    /**
     * @return array{reminders: int, reduced: int}
     */
    public function run(): array
    {
        $reminders = 0;
        $reduced = 0;

        $open = Invoice::query()->where('kind', 'invoice')->whereNull('paid_at')->where('due_on', '<', CarbonImmutable::today()->toDateString())->get();

        foreach ($open as $invoice) {
            if ($invoice->balance() === 0) {
                continue;
            }

            $days = $invoice->daysOverdue();
            $sent = $invoice->reminders ?? [];

            foreach (self::REMINDER_DAYS as $day) {
                if ($days >= $day && ! isset($sent["day_{$day}"])) {
                    $sent["day_{$day}"] = now()->toIso8601String();
                    $invoice->forceFill(['reminders' => $sent])->save();
                    $email = $invoice->customer['email'] ?? null;
                    if (is_string($email) && $email !== '') {
                        Mail::to($email)->queue(new InvoiceMail($invoice->id, 'reminder'));
                    }
                    $reminders++;

                    break;
                }
            }
        }

        foreach ($open->groupBy('hotel_id') as $hotelId => $invoices) {
            $worst = $invoices->filter(fn (Invoice $i): bool => $i->balance() > 0)->max(fn (Invoice $i): int => $i->daysOverdue()) ?? 0;
            $subscription = Subscription::query()->withoutGlobalScopes()->where('hotel_id', $hotelId)->first();

            if ($subscription === null || $subscription->reduced_at !== null || $worst < self::REDUCED_AFTER_DAYS) {
                continue;
            }

            if ($subscription->grace_until !== null && ! $subscription->grace_until->lt(CarbonImmutable::today())) {
                continue;
            }

            $subscription->forceFill(['reduced_at' => now()])->save();
            Audit::record('billing.reduced_mode_on', 'Reduced mode: unpaid invoice', subject: Hotel::query()->find($hotelId), properties: ['days_overdue' => $worst]);
            $reduced++;
        }

        return ['reminders' => $reminders, 'reduced' => $reduced];
    }

    /**
     * Lift reduced mode when nothing is overdue by 14 days or more.
     */
    public function liftIfPaid(string $hotelId): void
    {
        $subscription = Subscription::query()->withoutGlobalScopes()->where('hotel_id', $hotelId)->first();

        if ($subscription === null || $subscription->reduced_at === null) {
            return;
        }

        $stillLate = Invoice::query()->where('hotel_id', $hotelId)->where('kind', 'invoice')->whereNull('paid_at')->get()
            ->contains(fn (Invoice $i): bool => $i->balance() > 0 && $i->daysOverdue() >= self::REDUCED_AFTER_DAYS);

        if (! $stillLate) {
            $subscription->forceFill(['reduced_at' => null])->save();
            Audit::record('billing.reduced_mode_off', 'Reduced mode lifted', subject: Hotel::query()->find($hotelId));
        }
    }

    public function grantDelay(string $hotelId, CarbonImmutable $until, string $reason, User $by): void
    {
        $subscription = Subscription::query()->withoutGlobalScopes()->where('hotel_id', $hotelId)->firstOrFail();
        $subscription->forceFill(['grace_until' => $until, 'reduced_at' => null])->save();
        Audit::record('billing.delay_granted', 'Payment delay granted', subject: Hotel::query()->find($hotelId), causer: $by, properties: ['until' => $until->toDateString(), 'reason' => $reason]);
    }

    public static function isReduced(): bool
    {
        $context = app(\App\Domains\Tenancy\TenantContext::class);

        return $context->has() && Subscription::query()->whereNotNull('reduced_at')->exists();
    }
}
