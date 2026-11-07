<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Invoices\Invoicing;
use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Subscriptions;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Online payments of invoices and credit packs. A payment counts only once
 * the provider confirms it server-to-server, for the exact amount and
 * currency, and is applied once whatever the number of notifications.
 */
final class Checkouts
{
    public function __construct(private readonly TenantContext $context) {}

    public function forInvoice(Invoice $invoice, PaymentGateway $gateway, User $by): PaymentCheckout
    {
        if ($invoice->hotel_id !== $this->context->id() || $invoice->kind !== 'invoice' || $invoice->balance() <= 0) {
            throw new PaymentFailed((string) __('This invoice has nothing left to pay.'));
        }

        return $this->start($gateway, 'invoice', $invoice->balance(), $invoice->currency, (string) __('Invoice :number', ['number' => $invoice->number]), $by, invoice: $invoice);
    }

    public function forPack(CreditPack $pack, PaymentGateway $gateway, User $by): PaymentCheckout
    {
        $subscription = Subscription::query()->first();
        $currency = $subscription->currency ?? $this->context->hotel()->currency;
        $price = $pack->prices[$currency] ?? null;

        if ($subscription === null || $price === null || ! $pack->active) {
            throw new PaymentFailed((string) __('This pack cannot be bought in your currency.'));
        }

        return $this->start($gateway, 'pack', app(Invoicing::class)->totalFor((int) $price), $currency, (string) __('Credit pack :pack', ['pack' => $pack->localizedName(app()->getLocale())]), $by, pack: $pack);
    }

    /**
     * Ask the provider and apply the payment if it is confirmed.
     */
    public function settle(PaymentCheckout $checkout): PaymentCheckout
    {
        if ($checkout->status !== 'pending') {
            return $checkout;
        }

        $gateway = PaymentGateway::query()->findOrFail($checkout->payment_gateway_id);
        $result = $gateway->driver()->verify($checkout);

        if ($result->status === 'failed') {
            $checkout->forceFill(['status' => 'failed', 'failure' => mb_substr((string) $result->message, 0, 300)])->save();

            return $checkout;
        }

        if ($result->status !== 'paid') {
            return $checkout;
        }

        if ($result->amountMinor !== $checkout->amount_minor || strtoupper((string) $result->currency) !== $checkout->currency) {
            $checkout->forceFill(['status' => 'failed', 'failure' => 'Amount or currency differs from the provider\'s confirmation.'])->save();
            Audit::record('billing.online_payment_mismatch', 'Online payment did not match', subject: Hotel::query()->find($checkout->hotel_id), properties: ['reference' => $checkout->reference, 'expected' => $checkout->amount_minor, 'confirmed' => $result->amountMinor]);

            return $checkout;
        }

        DB::transaction(function () use ($checkout, $result, $gateway): void {
            $locked = PaymentCheckout::query()->lockForUpdate()->findOrFail($checkout->id);

            if ($locked->status !== 'pending') {
                return;
            }

            $invoice = $locked->kind === 'invoice'
                ? Invoice::query()->findOrFail($locked->invoice_id)
                : $this->invoicePack($locked);

            if ($invoice !== null) {
                app(Invoicing::class)->recordPayment($invoice, $locked->amount_minor, $gateway->driver, $locked->reference, CarbonImmutable::today(), null, $result->providerReference);
            }

            $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            $checkout->setRawAttributes($locked->getAttributes(), true);
        });

        Audit::record('billing.online_payment', 'Online payment received', subject: Hotel::query()->find($checkout->hotel_id), properties: ['reference' => $checkout->reference, 'gateway' => $gateway->driver, 'amount_minor' => $checkout->amount_minor]);

        return $checkout;
    }

    private function invoicePack(PaymentCheckout $checkout): ?Invoice
    {
        $pack = CreditPack::query()->findOrFail($checkout->credit_pack_id);
        $change = app(Subscriptions::class)->addPack($pack, null);

        return $change === null ? null : app(Invoicing::class)->issueDue(null, [$change->id]);
    }

    private function start(PaymentGateway $gateway, string $kind, int $amount, string $currency, string $description, User $by, ?Invoice $invoice = null, ?CreditPack $pack = null): PaymentCheckout
    {
        if (! $gateway->active || ! $gateway->supports($currency)) {
            throw new PaymentFailed((string) __('This payment method is not available.'));
        }

        $checkout = PaymentCheckout::query()->create([
            'payment_gateway_id' => $gateway->id, 'kind' => $kind, 'invoice_id' => $invoice?->id, 'credit_pack_id' => $pack?->id,
            'amount_minor' => $amount, 'currency' => $currency, 'reference' => 'HRS-'.Str::upper(Str::random(20)), 'status' => 'pending', 'created_by' => $by->getKey(),
        ]);

        $started = $gateway->driver()->start($checkout, [
            'email' => (string) $by->email,
            'name' => (string) $by->name,
            'description' => $description,
            'return_url' => route('payments.return', $checkout),
            'notify_url' => route('webhooks.payments', $gateway->driver),
        ]);

        $checkout->forceFill(['checkout_url' => $started['url'], 'provider_token' => $started['token']])->save();
        Audit::record('billing.online_payment_started', 'Online payment started', subject: $this->context->hotel(), causer: $by, properties: ['reference' => $checkout->reference, 'gateway' => $gateway->driver, 'amount_minor' => $amount]);

        return $checkout;
    }
}
