<?php

declare(strict_types=1);

use App\Domains\Access\HotelRoles;
use App\Domains\Access\Models\Role;
use App\Domains\Access\StaffService;
use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->hotel = Hotel::factory()->create();
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
});

it('creates a custom role, assigns it and applies its permissions', function (): void {
    asStaffOf($this->hotel, $this->owner)
        ->post(route('staff.roles.store'), ['name' => 'Night auditor', 'permissions' => ['staff.view', 'audit.view']])
        ->assertSessionHasNoErrors();

    $role = Role::query()->where('hotel_id', $this->hotel->id)->where('name', 'Night auditor')->sole();
    $user = User::factory()->create();
    app(StaffService::class)->assign($this->hotel, $user, $role);

    $can = app(TenantContext::class)->run($this->hotel, fn (): array => [
        $user->can('audit.view'),
        $user->can('staff.manage'),
    ]);

    expect($role->is_system)->toBeFalse()
        ->and($can)->toBe([true, false])
        ->and(AuditLog::query()->where('event', 'roles.created')->exists())->toBeTrue();
});

it('rejects unknown permissions and reserved or duplicate names', function (): void {
    $as = asStaffOf($this->hotel, $this->owner);

    $as->post(route('staff.roles.store'), ['name' => 'X', 'permissions' => ['bookings.delete-everything']])
        ->assertSessionHasErrors('permissions.0');
    $as->post(route('staff.roles.store'), ['name' => 'owner', 'permissions' => []])
        ->assertSessionHasErrors('name');

    $as->post(route('staff.roles.store'), ['name' => 'Cashier', 'permissions' => []]);
    $as->post(route('staff.roles.store'), ['name' => 'Cashier', 'permissions' => []])->assertSessionHasErrors('name');
});

it('allows the same custom role name in different hotels', function (): void {
    $other = Hotel::factory()->create();
    $otherOwner = staffMember($other, SystemRole::Owner);

    asStaffOf($this->hotel, $this->owner)->post(route('staff.roles.store'), ['name' => 'Cashier', 'permissions' => []])->assertSessionHasNoErrors();
    asStaffOf($other, $otherOwner)->post(route('staff.roles.store'), ['name' => 'Cashier', 'permissions' => []])->assertSessionHasNoErrors();

    expect(Role::query()->where('name', 'Cashier')->count())->toBe(2);
});

it('does not let anyone edit or delete system roles', function (): void {
    $reception = systemRole($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $this->owner)
        ->put(route('staff.roles.update', $reception->id), ['name' => 'Front desk', 'permissions' => []])
        ->assertForbidden();
    asStaffOf($this->hotel, $this->owner)
        ->delete(route('staff.roles.destroy', $reception->id))
        ->assertForbidden();
});

it('does not touch another hotel\'s custom roles', function (): void {
    $other = Hotel::factory()->create();
    $foreign = Role::query()->create(['hotel_id' => $other->id, 'name' => 'Foreign', 'guard_name' => HotelRoles::GUARD]);

    asStaffOf($this->hotel, $this->owner)
        ->delete(route('staff.roles.destroy', $foreign->id))
        ->assertNotFound();
});

it('refuses to delete a custom role that is in use', function (): void {
    $role = Role::query()->create(['hotel_id' => $this->hotel->id, 'name' => 'Cashier', 'guard_name' => HotelRoles::GUARD]);
    app(StaffService::class)->assign($this->hotel, User::factory()->create(), $role);

    asStaffOf($this->hotel, $this->owner)->delete(route('staff.roles.destroy', $role->id));

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
});
