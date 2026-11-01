<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rates\Models\RateOverride;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'default_locale' => 'fr']);
    $this->manager = staffMember($this->hotel, SystemRole::Manager);
    $this->exe = roomCategory($this->hotel, 'EXE', 2, ['extra_bed_allowed' => true]);
});

function ratePayload(array $overrides = []): array
{
    return [
        'base' => '25000', 'extra_adult' => '5000', 'child' => '2500', 'extra_bed' => '5000',
        'breakfast_mode' => 'optional', 'breakfast' => '3000', 'min_nights' => 1,
        ...$overrides,
    ];
}

it('saves a category rate in minor units and audits it', function (): void {
    asStaffOf($this->hotel, $this->manager)
        ->put(route('hotel.rates.category', $this->exe->id), ratePayload())
        ->assertSessionHasNoErrors();

    $rate = app(TenantContext::class)->run($this->hotel, fn () => CategoryRate::query()->sole());

    expect($rate->base_minor)->toBe(25000)
        ->and($rate->breakfast_minor)->toBe(3000)
        ->and(AuditLog::query()->where('event', 'rates.updated')->exists())->toBeTrue();
});

it('validates rates', function (): void {
    asStaffOf($this->hotel, $this->manager)
        ->put(route('hotel.rates.category', $this->exe->id), ratePayload(['base' => '0', 'breakfast' => null, 'min_nights' => 0, 'breakfast_mode' => 'optional']))
        ->assertSessionHasErrors(['base', 'breakfast', 'min_nights']);
});

it('creates, updates and deletes overrides', function (): void {
    $as = asStaffOf($this->hotel, $this->manager);
    $as->post(route('hotel.rate-overrides.store'), [
        'room_category_id' => $this->exe->id, 'from_date' => '2026-12-20', 'to_date' => '2027-01-02',
        'weekdays' => [5, 6], 'rate' => '30000', 'min_nights' => 2, 'stop_sell' => false, 'closed_to_arrival' => false, 'label' => 'Fêtes',
    ])->assertSessionHasNoErrors();

    $override = app(TenantContext::class)->run($this->hotel, fn () => RateOverride::query()->sole());
    expect($override->rate_minor)->toBe(30000)->and($override->weekdays)->toBe([5, 6]);

    asStaffOf($this->hotel, $this->manager)->put(route('hotel.rate-overrides.update', $override->id), [
        'room_category_id' => null, 'from_date' => '2026-12-20', 'to_date' => '2027-01-02', 'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'rate' => null, 'min_nights' => null, 'stop_sell' => true, 'closed_to_arrival' => false, 'label' => 'Fermé',
    ])->assertSessionHasNoErrors();

    $override->refresh();
    expect($override->weekdays)->toBeNull()->and($override->stop_sell)->toBeTrue()->and($override->room_category_id)->toBeNull();

    asStaffOf($this->hotel, $this->manager)->delete(route('hotel.rate-overrides.destroy', $override->id));
    expect(AuditLog::query()->whereIn('event', ['rates.override_created', 'rates.override_updated', 'rates.override_deleted'])->count())->toBe(3);
});

it('rejects overrides that change nothing or have reversed dates', function (): void {
    asStaffOf($this->hotel, $this->manager)->post(route('hotel.rate-overrides.store'), [
        'from_date' => '2026-12-20', 'to_date' => '2026-12-10', 'stop_sell' => false, 'closed_to_arrival' => false, 'label' => 'x',
    ])->assertSessionHasErrors('to_date');

    asStaffOf($this->hotel, $this->manager)->post(route('hotel.rate-overrides.store'), [
        'from_date' => '2026-12-20', 'to_date' => '2026-12-21', 'stop_sell' => false, 'closed_to_arrival' => false, 'label' => 'x',
    ])->assertSessionHasErrors('rate');
});

it('saves child age bands and refuses gaps or overlaps', function (): void {
    asStaffOf($this->hotel, $this->manager)->put(route('hotel.rates.settings'), [
        'child_age_bands' => [['from' => 0, 'to' => 5, 'charge' => 'free'], ['from' => 6, 'to' => 11, 'charge' => 'child']],
        'hold_minutes' => 30,
    ])->assertSessionHasNoErrors();

    $settings = app(TenantContext::class)->run($this->hotel, fn () => HotelSettings::current());
    expect($settings->hold_minutes)->toBe(30)->and($settings->child_age_bands)->toHaveCount(2);

    asStaffOf($this->hotel, $this->manager)->put(route('hotel.rates.settings'), [
        'child_age_bands' => [['from' => 0, 'to' => 5, 'charge' => 'free'], ['from' => 8, 'to' => 11, 'charge' => 'child']],
        'hold_minutes' => 30,
    ])->assertSessionHasErrors('child_age_bands.1.from');
});

it('locks the currency once rates exist', function (): void {
    rateFor($this->hotel, $this->exe);
    $owner = staffMember($this->hotel, SystemRole::Owner);

    asStaffOf($this->hotel, $owner)->put(route('hotel.settings.update'), [
        'name' => 'Lodge', 'check_in_time' => '14:00', 'check_out_time' => '12:00', 'timezone' => 'Africa/Abidjan',
        'currency' => 'EUR', 'default_locale' => 'fr', 'enabled_locales' => ['fr'],
    ])->assertSessionHasErrors('currency');
});

it('quotes a stay from the rates page', function (): void {
    rateFor($this->hotel, $this->exe);

    asStaffOf($this->hotel, $this->manager)
        ->get(route('hotel.rates', ['category' => $this->exe->id, 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2, 'rooms' => 1]), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new App\Http\Middleware\HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'hotel/rates',
            'X-Inertia-Partial-Data' => 'quote',
        ])
        ->assertOk()
        ->assertJsonPath('props.quote.bookable', true)
        ->assertJsonPath('props.quote.nights.0.available', 2);
});

it('lets reception view rates but not change them', function (): void {
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->get(route('hotel.rates'))->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
    asStaffOf($this->hotel, $reception)->put(route('hotel.rates.category', $this->exe->id), ratePayload())->assertForbidden();
});

it('keeps rates and overrides separate between hotels', function (): void {
    $other = Hotel::factory()->create();
    $foreignCategory = roomCategory($other, 'X', 1);
    $foreignOverride = overrideFor($other, null, '2026-12-01', '2026-12-02', ['stop_sell' => true]);

    asStaffOf($this->hotel, $this->manager)->put(route('hotel.rates.category', $foreignCategory->id), ratePayload())->assertNotFound();
    asStaffOf($this->hotel, $this->manager)->delete(route('hotel.rate-overrides.destroy', $foreignOverride->id))->assertNotFound();
    asStaffOf($this->hotel, $this->manager)->post(route('hotel.rate-overrides.store'), [
        'room_category_id' => $foreignCategory->id, 'from_date' => '2026-12-20', 'to_date' => '2026-12-21', 'stop_sell' => true, 'closed_to_arrival' => false, 'label' => 'x',
    ])->assertSessionHasErrors('room_category_id');
});
