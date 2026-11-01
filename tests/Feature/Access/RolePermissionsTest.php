<?php

declare(strict_types=1);

use App\Domains\Access\Permission;
use App\Domains\Access\SystemRole;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/helpers.php';

dataset('system roles', fn (): array => array_map(fn (SystemRole $role): array => [$role], SystemRole::cases()));

it('gives each system role exactly its permissions', function (SystemRole $role): void {
    $hotel = Hotel::factory()->create();
    $user = staffMember($hotel, $role);
    $expected = array_map(fn (Permission $p): string => $p->value, $role->permissions());

    app(TenantContext::class)->run($hotel, function () use ($user, $expected): void {
        foreach (Permission::cases() as $permission) {
            expect($user->can($permission->value))
                ->toBe(in_array($permission->value, $expected, true), "Permission {$permission->value}");
        }
    });
})->with('system roles');

it('gives a role in one hotel no rights in another', function (): void {
    $hotelA = Hotel::factory()->create();
    $hotelB = Hotel::factory()->create();
    $user = staffMember($hotelA, SystemRole::Manager);
    app(App\Domains\Access\StaffService::class)->assign($hotelB, $user, systemRole($hotelB, SystemRole::RestaurantBar));

    $context = app(TenantContext::class);

    expect($context->run($hotelA, fn (): bool => $user->unsetRelation('roles')->unsetRelation('permissions')->can('staff.manage')))->toBeTrue()
        ->and($context->run($hotelB, fn (): bool => $user->unsetRelation('roles')->unsetRelation('permissions')->can('staff.manage')))->toBeFalse();

    asStaffOf($hotelB, $user)->get(route('staff.index'))->assertForbidden();
    asStaffOf($hotelA, $user)->get(route('staff.index'))->assertOk();
});

it('protects hotel routes by permission', function (): void {
    $hotel = Hotel::factory()->create();

    asStaffOf($hotel, staffMember($hotel, SystemRole::Reception))->get(route('staff.index'))->assertOk();
    asStaffOf($hotel, staffMember($hotel, SystemRole::Reception))->get(route('staff.roles.index'))->assertForbidden();
    asStaffOf($hotel, staffMember($hotel, SystemRole::RestaurantBar))->get(route('staff.index'))->assertForbidden();
    asStaffOf($hotel, staffMember($hotel, SystemRole::Owner))->get(route('staff.roles.index'))->assertOk();
});

it('shares the current hotel permissions with the frontend', function (): void {
    $hotel = Hotel::factory()->create();

    asStaffOf($hotel, staffMember($hotel, SystemRole::ReadOnly))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($permissions): bool => collect($permissions)->sort()->values()->all() === ['audit.view', 'availability.view', 'hotel.settings.view', 'rates.view', 'rooms.view', 'staff.view', 'taxes.view', 'usage.view']));
});

it('keeps system roles in sync with the code', function (): void {
    $hotel = Hotel::factory()->create();
    systemRole($hotel, SystemRole::Reception)->syncPermissions([]);

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(systemRole($hotel, SystemRole::Reception)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['availability.manage', 'availability.view', 'rates.view', 'rooms.view', 'staff.view']);
});
