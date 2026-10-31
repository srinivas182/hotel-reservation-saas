<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'default_locale' => 'fr']);
    $this->manager = staffMember($this->hotel, SystemRole::Manager);
});

function taxPayload(array $overrides = []): array
{
    return [
        'name' => ['fr' => 'Taxe de séjour'],
        'type' => 'fixed_per_person_per_night',
        'percentage' => null,
        'amount' => '1000',
        'inclusive' => false,
        'applies_to' => ['rooms'],
        'active' => true,
        'effective_from' => null,
        'effective_to' => null,
        'sort_order' => 1,
        ...$overrides,
    ];
}

it('creates a fixed tax in minor units and audits it', function (): void {
    asStaffOf($this->hotel, $this->manager)
        ->post(route('hotel.taxes.store'), taxPayload())
        ->assertSessionHasNoErrors();

    $rate = app(TenantContext::class)->run($this->hotel, fn () => TaxRate::query()->sole());

    expect($rate->amount_minor)->toBe(1000)
        ->and($rate->percentage_bps)->toBeNull()
        ->and($rate->name)->toBe(['fr' => 'Taxe de séjour'])
        ->and(AuditLog::query()->where('event', 'taxes.created')->exists())->toBeTrue();
});

it('stores percentages in basis points', function (): void {
    asStaffOf($this->hotel, $this->manager)
        ->post(route('hotel.taxes.store'), taxPayload(['type' => 'percentage', 'percentage' => '18.5', 'amount' => null, 'inclusive' => true]))
        ->assertSessionHasNoErrors();

    expect(app(TenantContext::class)->run($this->hotel, fn () => TaxRate::query()->sole()->percentage_bps))->toBe(1850);
});

it('validates tax input', function (): void {
    $as = asStaffOf($this->hotel, $this->manager);

    $as->post(route('hotel.taxes.store'), taxPayload(['type' => 'percentage', 'percentage' => '150', 'amount' => null]))
        ->assertSessionHasErrors('percentage');
    $as->post(route('hotel.taxes.store'), taxPayload(['amount' => null]))->assertSessionHasErrors('amount');
    $as->post(route('hotel.taxes.store'), taxPayload(['name' => ['fr' => '']]))->assertSessionHasErrors('name.fr');
    $as->post(route('hotel.taxes.store'), taxPayload(['applies_to' => ['spa']]))->assertSessionHasErrors('applies_to.0');
    $as->post(route('hotel.taxes.store'), taxPayload(['effective_from' => '2026-12-01', 'effective_to' => '2026-11-01']))
        ->assertSessionHasErrors('effective_to');
});

it('previews taxes on a price', function (): void {
    asStaffOf($this->hotel, $this->manager)->post(route('hotel.taxes.store'), taxPayload());

    asStaffOf($this->hotel, $this->manager)
        ->get(route('hotel.taxes.index', ['price' => 50000, 'nights' => 2, 'guests' => 2, 'scope' => 'rooms']), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new App\Http\Middleware\HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'hotel/taxes',
            'X-Inertia-Partial-Data' => 'preview',
        ])
        ->assertOk()
        ->assertJsonPath('props.preview.lines.0.inclusive', false)
        ->assertJsonPath('props.preview.total', fn (string $total): bool => str_contains(preg_replace('/\D/', '', $total), '54000'));
});

it('lets read-only staff view but not change taxes', function (): void {
    $readOnly = staffMember($this->hotel, SystemRole::ReadOnly);

    asStaffOf($this->hotel, $readOnly)->get(route('hotel.taxes.index'))->assertOk();
    asStaffOf($this->hotel, $readOnly)->post(route('hotel.taxes.store'), taxPayload())->assertForbidden();
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->get(route('hotel.taxes.index'))->assertForbidden();
});

it('keeps tax rates separate between hotels', function (): void {
    $other = Hotel::factory()->create();
    $foreign = app(TenantContext::class)->run($other, fn () => TaxRate::query()->create([
        'name' => ['en' => 'VAT'], 'type' => 'percentage', 'percentage_bps' => 1800, 'applies_to' => ['rooms'],
    ]));

    asStaffOf($this->hotel, $this->manager)
        ->put(route('hotel.taxes.update', $foreign->id), taxPayload())
        ->assertNotFound();
    asStaffOf($this->hotel, $this->manager)
        ->delete(route('hotel.taxes.destroy', $foreign->id))
        ->assertNotFound();
    asStaffOf($this->hotel, $this->manager)
        ->get(route('hotel.taxes.index'))
        ->assertInertia(fn ($page) => $page->has('rates', 0));
});
