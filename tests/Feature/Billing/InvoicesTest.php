<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Billing\Mail\InvoiceMail;
use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionChange;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

function cnh(array $overrides = []): BillingEntity
{
    return BillingEntity::query()->create([
        'name' => 'CNH Global Tech', 'legal_name' => 'CNH Global Tech SARL', 'country_code' => 'CI', 'address' => 'Abidjan, Cocody',
        'registration' => 'CI-ABJ-2024-B-12345', 'tax_id' => '2400000A', 'email' => 'factures@cnh.example', 'invoice_prefix' => 'CNH',
        'tax_rules' => [['name' => 'TVA', 'rate_bps' => 1800]], 'payment_terms_days' => 7, 'payment_instructions' => 'Orange Money 07 00 00 00 00', 'is_default' => true,
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    Mail::fake();
    $this->travelTo('2026-11-01 08:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'legal_name' => 'First Class Lodge SARL']);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->plan = billingPlan('pro', [], null, 100, 50, 25000);
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
});

it('invoices a new period with the issuing company\'s taxes and numbering, and emails the PDF', function (): void {
    cnh();
    subscribe($this->hotel, $this->plan);
    ($this->in)(fn () => Subscription::query()->first()->update(['billing_details' => ['email' => 'compta@fcl.example', 'tax_id' => 'NCC-999']]));

    $this->artisan('billing:invoice')->assertSuccessful();

    $invoice = Invoice::query()->with('lines')->sole();
    expect($invoice->number)->toBe('CNH-2026-00001')
        ->and($invoice->subtotal_minor)->toBe(25000)
        ->and($invoice->tax_minor)->toBe(4500)
        ->and($invoice->total_minor)->toBe(29500)
        ->and($invoice->due_on->toDateString())->toBe('2026-11-08')
        ->and($invoice->customer['name'])->toBe('First Class Lodge SARL')
        ->and($invoice->customer['tax_id'])->toBe('NCC-999')
        ->and($invoice->issuer['registration'])->toBe('CI-ABJ-2024-B-12345')
        ->and($invoice->lines->first()->description)->toContain('Pro')
        ->and(($this->in)(fn () => SubscriptionChange::query()->whereNull('invoiced_at')->count()))->toBe(0);
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $m): bool => $m->hasTo('compta@fcl.example') && $m->kind === 'issued');

    // Nothing new: no second invoice.
    $this->artisan('billing:invoice');
    expect(Invoice::query()->count())->toBe(1);
});

it('handles prices that include tax, trials (nothing to invoice) and a missing issuing company', function (): void {
    subscribe($this->hotel, $this->plan, trial: true);
    $this->artisan('billing:invoice');
    expect(Invoice::query()->count())->toBe(0);

    $this->travelTo('2026-11-15 09:00:00');
    $this->artisan('billing:renew');
    $this->artisan('billing:invoice');
    expect(Invoice::query()->count())->toBe(0) // no issuing company yet: the change waits
        ->and(($this->in)(fn () => SubscriptionChange::query()->whereNull('invoiced_at')->where('amount_minor', '>', 0)->count()))->toBe(1);

    cnh(['prices_include_tax' => true]);
    $this->artisan('billing:invoice');
    $invoice = Invoice::query()->sole();
    expect($invoice->total_minor)->toBe(25000)->and($invoice->subtotal_minor)->toBe(21186)->and($invoice->tax_minor)->toBe(3814);
});

it('records payments, issues credit notes in the same sequence, and never deletes invoices', function (): void {
    cnh();
    subscribe($this->hotel, $this->plan);
    $this->artisan('billing:invoice');
    $invoice = Invoice::query()->sole();

    $this->actingAs($this->admin)->post(route('platform.invoices.credit-note', $invoice), ['amount' => 5900, 'reason' => 'Geste commercial'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('platform.invoices.credit-note', $invoice), ['amount' => 50000, 'reason' => 'Trop'])->assertSessionHasErrors('amount');
    $this->actingAs($this->admin)->post(route('platform.invoices.payments', $invoice), ['amount' => 20000, 'method' => 'orange_money', 'reference' => 'OM-1', 'received_on' => '2026-11-01'])->assertSessionHasNoErrors();

    $credit = Invoice::query()->where('kind', 'credit_note')->sole();
    $invoice = $invoice->fresh();
    expect($credit->number)->toBe('CNH-AV-2026-00002')
        ->and($credit->total_minor)->toBe(5900)
        ->and($invoice->balance())->toBe(3600)
        ->and($invoice->status())->toBe('open');

    $this->actingAs($this->admin)->post(route('platform.invoices.payments', $invoice), ['amount' => 3600, 'method' => 'cash', 'received_on' => '2026-11-01']);
    expect($invoice->fresh()->status())->toBe('paid')->and($invoice->fresh()->paid_at)->not->toBeNull()
        ->and($invoice->delete())->toBeFalse();
});

it('reminds, switches to reduced mode after 14 days, and lifts it on payment', function (): void {
    ['hotel' => $hotel] = assistantHotelForBilling();
    cnh();
    subscribe($hotel, $this->plan);
    app(TenantContext::class)->run($hotel, fn () => Subscription::query()->first()->update(['billing_details' => ['email' => 'compta@fcl.example']]));
    $this->artisan('billing:invoice');
    $invoice = Invoice::query()->sole();
    $owner = staffMember($hotel, SystemRole::Owner);

    foreach (['2026-11-11' => 1, '2026-11-15' => 2, '2026-11-18' => 3] as $day => $reminders) {
        $this->travelTo($day.' 08:00:00');
        $this->artisan('billing:dunning');
        expect(count($invoice->fresh()->reminders ?? []))->toBe($reminders);
    }
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $m): bool => $m->kind === 'reminder');

    $this->travelTo('2026-11-22 08:00:00');
    $this->artisan('billing:dunning');
    expect(app(TenantContext::class)->run($hotel, fn () => App\Domains\Billing\Invoices\Dunning::isReduced()))->toBeTrue();

    // The assistant pauses; set-up is read-only; day-to-day work goes on.
    App\Domains\Assistant\Engine\HotelAssistantAgent::fake([aiReply('Bonjour')]);
    $result = app(TenantContext::class)->run($hotel, fn () => app(App\Domains\Assistant\Engine\AssistantEngine::class)->respond([App\Domains\Assistant\Engine\AssistantTurn::guest('Bonjour')]));
    expect($result->handoverReason)->toBe('ai_unavailable:reduced_mode');
    asStaffOf($hotel, $owner)->post(route('hotel.taxes.store'), ['name' => ['fr' => 'X'], 'type' => 'percentage', 'percentage' => 1, 'inclusive' => false, 'applies_to' => ['rooms']])->assertSessionHasErrors('reduced_mode');
    asStaffOf($hotel, $owner)->get(route('hotel.billing'))->assertOk()->assertInertia(fn ($page) => $page->where('reduced', true)->where('billingReduced', true)->has('invoices', 1));

    $this->flushSession();
    $this->actingAs($this->admin)->post(route('platform.invoices.payments', $invoice), ['amount' => 29500, 'method' => 'wave', 'received_on' => '2026-11-22'])->assertSessionHasNoErrors();
    expect(app(TenantContext::class)->run($hotel, fn () => App\Domains\Billing\Invoices\Dunning::isReduced()))->toBeFalse();
});

it('does not reduce a hotel the platform gave a delay', function (): void {
    cnh();
    subscribe($this->hotel, $this->plan);
    $this->artisan('billing:invoice');

    $this->actingAs($this->admin)->post(route('platform.hotels.billing.delay', $this->hotel), ['until' => '2026-12-15', 'reason' => 'Attente subvention'])->assertSessionHasNoErrors();
    $this->travelTo('2026-11-30 08:00:00');
    $this->artisan('billing:dunning');

    expect(($this->in)(fn () => App\Domains\Billing\Invoices\Dunning::isReduced()))->toBeFalse();
});

it('lets owners download only their own invoices as PDF', function (): void {
    cnh();
    subscribe($this->hotel, $this->plan);
    $this->artisan('billing:invoice');
    $invoice = Invoice::query()->sole();
    $owner = staffMember($this->hotel, SystemRole::Owner);

    $pdf = asStaffOf($this->hotel, $owner)->get(route('hotel.billing.invoice', $invoice))->assertOk();
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');

    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->get(route('hotel.billing.invoice', $invoice))->assertNotFound();
    $this->flushSession();
    $this->actingAs($this->admin)->get(route('platform.invoices.pdf', $invoice))->assertOk();
});

it('lets the platform set up issuing companies', function (): void {
    $this->actingAs($this->admin)->post(route('platform.billing.entities.store'), [
        'name' => 'CNH Global Tech', 'legal_name' => 'CNH Global Tech SARL', 'country_code' => 'ci', 'address' => 'Abidjan', 'registration' => 'RCCM', 'tax_id' => 'NCC',
        'email' => 'f@cnh.example', 'phone' => null, 'invoice_prefix' => 'CNH', 'tax_rules' => [['name' => 'TVA', 'rate' => 18]], 'prices_include_tax' => false,
        'payment_terms_days' => 7, 'payment_instructions' => 'Virement', 'footer' => null, 'is_default' => true, 'active' => true,
    ])->assertSessionHasNoErrors();

    $entity = BillingEntity::query()->sole();
    expect($entity->country_code)->toBe('CI')->and($entity->tax_rules)->toBe([['name' => 'TVA', 'rate_bps' => 1800]]);
    $this->actingAs(User::factory()->create())->post(route('platform.billing.entities.store'), [])->assertForbidden();
});

/**
 * @return array{hotel: Hotel}
 */
function assistantHotelForBilling(): array
{
    require_once __DIR__.'/../Assistant/helpers.php';

    return ['hotel' => assistantHotel()['hotel']];
}
