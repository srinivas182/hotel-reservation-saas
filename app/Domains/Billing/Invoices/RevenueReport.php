<?php

declare(strict_types=1);

namespace App\Domains\Billing\Invoices;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Messaging\Usage\UsageRecord;
use App\Domains\Tenancy\Models\Hotel;
use Carbon\CarbonImmutable;

/**
 * Monthly recurring revenue per currency, overdue amounts, and the AI cost
 * of each hotel this month (to watch margins).
 */
final class RevenueReport
{
    /**
     * @return array{mrr: array<string, string>, overdue: array<string, string>, hotels: list<array<string, mixed>>}
     */
    public static function build(): array
    {
        $since = CarbonImmutable::now()->startOfMonth();
        $subscriptions = Subscription::query()->withoutGlobalScopes()->with('plan')->where('status', '!=', 'cancelled')->get();
        $mrr = [];
        $hotels = [];

        $aiCost = UsageRecord::query()->withoutGlobalScopes()->where('meter', 'ai.cost_micro_usd')->where('occurred_at', '>=', $since)
            ->selectRaw('hotel_id, sum(quantity) as micro')->groupBy('hotel_id')->pluck('micro', 'hotel_id');

        foreach ($subscriptions as $s) {
            $monthly = $s->status === 'trialing' ? 0 : ($s->interval === 'yearly' ? intdiv($s->price_minor, 12) : $s->price_minor);
            $mrr[$s->currency] = ($mrr[$s->currency] ?? 0) + $monthly;
            $overdue = Invoice::query()->where('hotel_id', $s->hotel_id)->where('kind', 'invoice')->whereNull('paid_at')->get()->sum(fn (Invoice $i): int => $i->status() === 'overdue' ? $i->balance() : 0);

            $hotels[] = [
                'hotel_id' => $s->hotel_id,
                'hotel' => Hotel::query()->find($s->hotel_id)?->name,
                'plan' => $s->plan?->code,
                'status' => $s->reduced_at !== null ? 'reduced' : $s->status,
                'mrr' => InvoicePdf::money($monthly, $s->currency),
                'ai_cost_usd' => number_format(((int) ($aiCost[$s->hotel_id] ?? 0)) / 1_000_000, 2),
                'overdue' => $overdue > 0 ? InvoicePdf::money((int) $overdue, $s->currency) : null,
            ];
        }

        $overdueTotals = [];
        foreach (Invoice::query()->where('kind', 'invoice')->whereNull('paid_at')->get() as $invoice) {
            if ($invoice->status() === 'overdue') {
                $overdueTotals[$invoice->currency] = ($overdueTotals[$invoice->currency] ?? 0) + $invoice->balance();
            }
        }

        return [
            'mrr' => array_map(fn (int $v, string $c): string => InvoicePdf::money($v, $c), $mrr, array_keys($mrr)) === [] ? [] : array_combine(array_keys($mrr), array_map(fn (int $v, string $c): string => InvoicePdf::money($v, $c), $mrr, array_keys($mrr))),
            'overdue' => $overdueTotals === [] ? [] : array_combine(array_keys($overdueTotals), array_map(fn (int $v, string $c): string => InvoicePdf::money($v, $c), $overdueTotals, array_keys($overdueTotals))),
            'hotels' => $hotels,
        ];
    }
}
