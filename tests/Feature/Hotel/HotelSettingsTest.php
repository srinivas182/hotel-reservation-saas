<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/../Access/helpers.php';

function settingsPayload(array $overrides = []): array
{
    return [
        'name' => 'First Class Lodge',
        'legal_name' => 'Colena Investment',
        'phone_e164' => '+225 07 00 00 00 00',
        'whatsapp_display_e164' => '+2250700000001',
        'email' => 'reception@lodge.test',
        'address_line1' => 'Route Aéroport Latonon',
        'address_line2' => null,
        'city' => 'Korhogo',
        'map_url' => 'https://maps.example.com/lodge',
        'check_in_time' => '14:00',
        'check_out_time' => '12:00',
        'late_checkout_until' => '18:00',
        'staffed_hours' => ['mon' => [['from' => '07:00', 'to' => '22:00']]],
        'timezone' => 'Africa/Abidjan',
        'currency' => 'xof',
        'default_locale' => 'fr',
        'enabled_locales' => ['fr', 'en'],
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $this->hotel = Hotel::factory()->create(['default_locale' => 'fr']);
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
});

it('creates default settings for every new hotel', function (): void {
    $settings = app(TenantContext::class)->run($this->hotel, fn () => HotelSettings::query()->sole());

    expect($settings->enabled_locales)->toBe(['fr'])
        ->and(substr($settings->check_in_time, 0, 5))->toBe('14:00');
});

it('saves settings, normalises phone numbers and audits the change', function (): void {
    asStaffOf($this->hotel, $this->owner)
        ->put(route('hotel.settings.update'), settingsPayload())
        ->assertSessionHasNoErrors();

    $settings = app(TenantContext::class)->run($this->hotel, fn () => HotelSettings::query()->sole());

    expect($settings->phone_e164)->toBe('+2250700000000')
        ->and($settings->staffed_hours['mon'][0])->toEqual(['from' => '07:00', 'to' => '22:00']) // MySQL JSON does not keep key order
        ->and($settings->enabled_locales)->toBe(['fr', 'en'])
        ->and($this->hotel->refresh()->currency)->toBe('XOF')
        ->and(AuditLog::query()->where('event', 'hotel.settings_updated')->exists())->toBeTrue();
});

it('validates settings', function (): void {
    asStaffOf($this->hotel, $this->owner)
        ->put(route('hotel.settings.update'), settingsPayload([
            'phone_e164' => '0700',
            'late_checkout_until' => '11:00',
            'timezone' => 'Moon/Base',
            'currency' => 'ZZZ',
            'enabled_locales' => ['en'],
            'staffed_hours' => ['mon' => [['from' => '22:00', 'to' => '07:00']]],
        ]))
        ->assertSessionHasErrors(['phone_e164', 'late_checkout_until', 'timezone', 'currency', 'enabled_locales', 'staffed_hours.mon.0.to']);
});

it('lets read-only staff view settings but not change them', function (): void {
    $readOnly = staffMember($this->hotel, SystemRole::ReadOnly);

    asStaffOf($this->hotel, $readOnly)->get(route('hotel.settings.edit'))->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
    asStaffOf($this->hotel, $readOnly)->put(route('hotel.settings.update'), settingsPayload())->assertForbidden();
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->get(route('hotel.settings.edit'))->assertForbidden();
});

it('only changes the current hotel\'s settings', function (): void {
    $other = Hotel::factory()->create();

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.settings.update'), settingsPayload());

    $otherSettings = app(TenantContext::class)->run($other, fn () => HotelSettings::query()->sole());
    expect($otherSettings->phone_e164)->toBeNull();
});
