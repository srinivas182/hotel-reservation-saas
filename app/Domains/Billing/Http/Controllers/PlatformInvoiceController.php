<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Invoices\Dunning;
use App\Domains\Billing\Invoices\InvoicePdf;
use App\Domains\Billing\Invoices\Invoicing;
use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Platform side of invoicing: issuing companies, invoices, payments,
 * credit notes, delays, and the hotel's billing details.
 */
final class PlatformInvoiceController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function saveEntity(Request $request, #[CurrentUser] User $user, ?BillingEntity $entity = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['required', 'string', 'max:200'],
            'country_code' => ['required', 'string', 'size:2'],
            'address' => ['required', 'string', 'max:1000'],
            'registration' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'invoice_prefix' => ['required', 'alpha_dash', 'max:10'],
            'tax_rules' => ['array', 'max:5'],
            'tax_rules.*.name' => ['required', 'string', 'max:40'],
            'tax_rules.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'prices_include_tax' => ['required', 'boolean'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:90'],
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
            'footer' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ]);
        $data['country_code'] = strtoupper((string) $data['country_code']);
        $data['tax_rules'] = array_values(array_map(fn (array $r): array => ['name' => (string) $r['name'], 'rate_bps' => (int) round((float) $r['rate'] * 100)], (array) ($data['tax_rules'] ?? [])));

        if ((bool) $data['is_default']) {
            BillingEntity::query()->when($entity instanceof BillingEntity, fn ($q) => $q->whereKeyNot($entity?->id))->update(['is_default' => false]);
        }

        $entity === null ? $entity = BillingEntity::query()->create($data) : $entity->update($data);
        Audit::record('billing.entity_saved', 'Issuing company saved', subject: null, causer: $user, properties: ['entity' => $entity->name]);

        return back();
    }

    public function storeEntity(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveEntity($request, $user, null);
    }

    public function updateEntity(Request $request, BillingEntity $entity, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveEntity($request, $user, $entity);
    }

    public function details(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'billing_entity_id' => ['nullable', 'integer', Rule::exists('billing_entities', 'id')],
            'name' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:1000'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
        ]);

        $this->context->run($hotel, function () use ($data): void {
            $subscription = Subscription::query()->firstOrFail();
            $subscription->forceFill([
                'billing_entity_id' => $data['billing_entity_id'] ?? null,
                'billing_details' => array_filter(['name' => $data['name'] ?? null, 'address' => $data['address'] ?? null, 'tax_id' => $data['tax_id'] ?? null, 'email' => $data['email'] ?? null]),
            ])->save();
        });
        Audit::record('billing.details_saved', 'Billing details saved', subject: $hotel, causer: $user);

        return back();
    }

    public function issueNow(Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $invoice = $this->context->run($hotel, fn () => app(Invoicing::class)->issueDue($user));

        if ($invoice === null && BillingEntity::default() === null) {
            throw ValidationException::withMessages(['invoice' => __('Add an issuing company first.')]);
        }

        return back();
    }

    public function payment(Request $request, Invoice $invoice, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'method' => ['required', Rule::in([...RestaurantSettings::METHODS, 'other'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        try {
            app(Invoicing::class)->recordPayment($invoice, $this->minor((string) $data['amount'], $invoice->currency), (string) $data['method'], $data['reference'] ?? null, CarbonImmutable::parse((string) $data['received_on']), $user);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back();
    }

    public function creditNote(Request $request, Invoice $invoice, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:300']]);

        try {
            app(Invoicing::class)->creditNote($invoice, $this->minor((string) $data['amount'], $invoice->currency), (string) $data['reason'], $user);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back();
    }

    public function delay(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['until' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'reason' => ['required', 'string', 'max:300']]);
        app(Dunning::class)->grantDelay($hotel->id, CarbonImmutable::parse((string) $data['until']), (string) $data['reason'], $user);

        return back();
    }

    public function pdf(Invoice $invoice, InvoicePdf $pdf): Response
    {
        return response($pdf->render($invoice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"']);
    }

    private function minor(string $amount, string $currency): int
    {
        return Money::of($amount, $currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt();
    }
}
