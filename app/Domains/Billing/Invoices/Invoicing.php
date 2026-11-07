<?php

declare(strict_types=1);

namespace App\Domains\Billing\Invoices;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Mail\InvoiceMail;
use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\InvoiceLine;
use App\Domains\Billing\Models\InvoicePayment;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionChange;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Invoices to hotels: issued from the subscription changes waiting to be
 * invoiced (renewals, pro-rata upgrades, packs), numbered without gaps per
 * issuing company and year, corrected only with credit notes.
 */
final class Invoicing
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Invoice everything the current hotel owes and has not been invoiced.
     */
    /**
     * @param  list<int>|null  $onlyChangeIds  invoice just these changes (e.g. a pack paid online)
     */
    public function issueDue(?User $by = null, ?array $onlyChangeIds = null): ?Invoice
    {
        $hotel = $this->context->hotel();
        $subscription = Subscription::query()->first();

        if ($subscription === null) {
            return null;
        }

        $changes = SubscriptionChange::query()->whereNull('invoiced_at')->when($onlyChangeIds !== null, fn ($q) => $q->whereIn('id', $onlyChangeIds))->orderBy('id')->get();
        // Nothing to charge (trial start, scheduled downgrade…): just mark them.
        $free = $changes->where('amount_minor', '<=', 0);
        if ($free->isNotEmpty()) {
            SubscriptionChange::query()->whereIn('id', $free->pluck('id'))->update(['invoiced_at' => now()]);
        }
        $chargeable = $changes->where('amount_minor', '>', 0)->values();

        if ($chargeable->isEmpty()) {
            return null;
        }

        $entity = ($subscription->billing_entity_id !== null ? BillingEntity::query()->find($subscription->billing_entity_id) : null) ?? BillingEntity::default();

        if ($entity === null) {
            return null; // No issuing company set up yet: the platform is warned on its billing page.
        }

        $lines = $chargeable->map(fn (SubscriptionChange $c): array => [
            'description' => $this->describe($c, $subscription, $hotel->default_locale),
            'quantity' => 1,
            'unit_minor' => $c->amount_minor,
            'amount_minor' => $c->amount_minor,
            'subscription_change_id' => $c->id,
        ])->values()->all();

        $invoice = $this->issue($hotel, $entity, $subscription, $lines, 'invoice', null, null, $by);
        SubscriptionChange::query()->whereIn('id', $chargeable->pluck('id'))->update(['invoiced_at' => now()]);

        return $invoice;
    }

    /**
     * A credit note cancelling all or part of an invoice.
     */
    public function creditNote(Invoice $invoice, int $amountMinor, string $reason, User $by): Invoice
    {
        if ($invoice->kind !== 'invoice' || $amountMinor <= 0) {
            throw new InvalidArgumentException('A credit note needs an invoice and a positive amount.');
        }

        $alreadyCredited = (int) Invoice::query()->where('credits_invoice_id', $invoice->id)->sum('total_minor');

        if ($amountMinor > $invoice->total_minor - $alreadyCredited) {
            throw new InvalidArgumentException('The credit note is larger than what is left on the invoice.');
        }

        $hotel = Hotel::query()->findOrFail($invoice->hotel_id);
        $entity = BillingEntity::query()->findOrFail($invoice->billing_entity_id);

        // Credit notes carry the same tax split as the invoice, in proportion.
        $ratio = $amountMinor / max(1, $invoice->total_minor);
        $lines = [['description' => (string) __('Credit note for invoice :number', ['number' => $invoice->number]), 'quantity' => 1, 'unit_minor' => (int) round($invoice->subtotal_minor * $ratio), 'amount_minor' => (int) round($invoice->subtotal_minor * $ratio), 'subscription_change_id' => null]];

        return $this->issue($hotel, $entity, null, $lines, 'credit_note', $invoice, $reason, $by, $amountMinor);
    }

    public function recordPayment(Invoice $invoice, int $amountMinor, string $method, ?string $reference, CarbonImmutable $receivedOn, ?User $by, ?string $providerReference = null): InvoicePayment
    {
        if ($invoice->kind !== 'invoice' || $amountMinor <= 0) {
            throw new InvalidArgumentException('Payments go on invoices, with a positive amount.');
        }

        $payment = DB::transaction(function () use ($invoice, $amountMinor, $method, $reference, $receivedOn, $by, $providerReference): InvoicePayment {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $payment = InvoicePayment::query()->create([
                'invoice_id' => $locked->id, 'amount_minor' => $amountMinor, 'method' => $method, 'reference' => $reference,
                'received_on' => $receivedOn, 'provider_reference' => $providerReference, 'recorded_by' => $by?->getKey(),
            ]);
            $locked->forceFill(['paid_minor' => $locked->paid_minor + $amountMinor])->save();
            if ($locked->balance() === 0 && $locked->paid_at === null) {
                $locked->forceFill(['paid_at' => now()])->save();
            }
            $invoice->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });

        Audit::record('billing.payment_recorded', 'Invoice payment recorded', subject: Hotel::query()->find($invoice->hotel_id), causer: $by, properties: ['invoice' => $invoice->number, 'amount_minor' => $amountMinor, 'method' => $method, 'reference' => $reference]);
        app(Dunning::class)->liftIfPaid($invoice->hotel_id);

        return $payment;
    }

    /**
     * @param  array<int, array{description: string, quantity: int, unit_minor: int, amount_minor: int, subscription_change_id: int|null}>  $lines
     */
    private function issue(Hotel $hotel, BillingEntity $entity, ?Subscription $subscription, array $lines, string $kind, ?Invoice $credits, ?string $reason, ?User $by, ?int $creditTotal = null): Invoice
    {
        $currency = $credits->currency ?? $subscription->currency ?? $hotel->currency;
        [$subtotal, $taxes, $tax] = $this->taxes($entity, array_sum(array_column($lines, 'amount_minor')));

        if ($credits !== null && $creditTotal !== null) {
            // Proportional to the credited invoice; the total is exactly what was asked.
            $ratio = $creditTotal / max(1, $credits->total_minor);
            $taxes = array_map(fn (array $t): array => [...$t, 'amount_minor' => (int) round($t['amount_minor'] * $ratio)], $credits->taxes);
            $tax = array_sum(array_column($taxes, 'amount_minor'));
            $subtotal = $creditTotal - $tax;
            $lines[0]['unit_minor'] = $lines[0]['amount_minor'] = $subtotal;
        }

        $customer = $subscription->billing_details ?? Subscription::query()->withoutGlobalScopes()->where('hotel_id', $hotel->id)->value('billing_details');
        $settings = $this->context->run($hotel, fn () => HotelSettings::current());
        $customer = [
            'name' => $customer['name'] ?? ($hotel->legal_name ?: $hotel->name),
            'address' => $customer['address'] ?? trim(implode(', ', array_filter([$settings->address_line1, $settings->address_line2, $settings->city]))),
            'tax_id' => $customer['tax_id'] ?? null,
            'email' => $customer['email'] ?? ($settings->email ?? $hotel->owner?->email),
        ];

        $invoice = DB::transaction(function () use ($hotel, $entity, $lines, $kind, $credits, $reason, $by, $currency, $subtotal, $taxes, $tax, $customer): Invoice {
            $today = CarbonImmutable::today();
            $invoice = Invoice::query()->create([
                'hotel_id' => $hotel->id, 'billing_entity_id' => $entity->id, 'number' => $this->nextNumber($entity, $kind),
                'kind' => $kind, 'credits_invoice_id' => $credits?->id, 'currency' => $currency,
                'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $subtotal + $tax, 'taxes' => $taxes,
                'issuer' => $entity->snapshot(), 'customer' => $customer,
                'issued_on' => $today, 'due_on' => $kind === 'invoice' ? $today->addDays($entity->payment_terms_days) : $today,
                'reason' => $reason, 'created_by' => $by?->getKey(),
            ]);

            foreach ($lines as $line) {
                InvoiceLine::query()->create([...$line, 'invoice_id' => $invoice->id]);
            }

            return $invoice;
        });

        Audit::record($kind === 'invoice' ? 'billing.invoice_issued' : 'billing.credit_note_issued', $kind === 'invoice' ? 'Invoice issued' : 'Credit note issued', subject: $hotel, causer: $by, properties: ['number' => $invoice->number, 'total_minor' => $invoice->total_minor, 'reason' => $reason]);

        if ($customer['email'] !== null && $customer['email'] !== '') {
            Mail::to((string) $customer['email'])->queue(new InvoiceMail($invoice->id, 'issued'));
        }

        if ($credits !== null) {
            app(Dunning::class)->liftIfPaid($hotel->id);
        }

        return $invoice;
    }

    /**
     * What the hotel pays for an amount once the issuing company's taxes apply.
     */
    public function totalFor(int $amountMinor): int
    {
        $subscription = Subscription::query()->first();
        $entity = ($subscription?->billing_entity_id !== null ? BillingEntity::query()->find($subscription->billing_entity_id) : null) ?? BillingEntity::default();

        if ($entity === null) {
            return $amountMinor;
        }

        [$subtotal, , $tax] = $this->taxes($entity, $amountMinor);

        return $subtotal + $tax;
    }

    /**
     * @return array{0: int, 1: list<array{name: string, rate_bps: int, amount_minor: int}>, 2: int}
     */
    private function taxes(BillingEntity $entity, int $amount): array
    {
        $rules = $entity->tax_rules ?? [];
        $rate = array_sum(array_map(fn (array $r): int => (int) $r['rate_bps'], $rules));

        $subtotal = $entity->prices_include_tax && $rate > 0 ? (int) round($amount * 10000 / (10000 + $rate)) : $amount;
        $taxes = array_map(fn (array $r): array => ['name' => (string) $r['name'], 'rate_bps' => (int) $r['rate_bps'], 'amount_minor' => (int) round($subtotal * (int) $r['rate_bps'] / 10000)], $rules);
        $tax = array_sum(array_column($taxes, 'amount_minor'));

        if ($entity->prices_include_tax && $taxes !== []) {
            // Keep the total equal to the price; any rounding goes to the first tax.
            $taxes[0]['amount_minor'] += $amount - $subtotal - $tax;
            $tax = $amount - $subtotal;
        }

        return [$subtotal, $taxes, $tax];
    }

    private function nextNumber(BillingEntity $entity, string $kind): string
    {
        $year = (int) now()->format('Y');
        DB::table('invoice_sequences')->insertOrIgnore(['billing_entity_id' => $entity->id, 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('invoice_sequences')->where('billing_entity_id', $entity->id)->where('year', $year)->lockForUpdate()->first(['id', 'last_number']);
        $number = (int) ($row->last_number ?? 0) + 1;
        DB::table('invoice_sequences')->where('id', $row->id ?? 0)->update(['last_number' => $number, 'updated_at' => now()]);

        // One sequence for invoices and credit notes, so numbers never have gaps.
        return sprintf('%s%s-%d-%05d', $entity->invoice_prefix, $kind === 'credit_note' ? '-AV' : '', $year, $number);
    }

    private function describe(SubscriptionChange $change, Subscription $subscription, string $locale): string
    {
        $plan = $change->to_plan_id === null ? null : Plan::query()->find($change->to_plan_id);
        $from = $change->from_plan_id === null ? null : Plan::query()->find($change->from_plan_id);
        $name = $plan?->localizedName($locale) ?? '';
        $interval = $subscription->interval === 'yearly' ? __('yearly') : __('monthly');

        return match (true) {
            str_starts_with($change->kind, 'pack:') => (string) __('Credit pack :pack', ['pack' => CreditPack::query()->where('code', substr($change->kind, 5))->first()?->localizedName($locale) ?? substr($change->kind, 5)]),
            $change->kind === 'upgraded' => (string) __('Change from :from to :to for the rest of the period (pro rata)', ['from' => $from?->localizedName($locale) ?? '', 'to' => $name]),
            default => (string) __('Subscription :plan (:interval) from :date', ['plan' => $name, 'interval' => $interval, 'date' => ($change->created_at ?? now())->toDateString()]),
        };
    }
}
