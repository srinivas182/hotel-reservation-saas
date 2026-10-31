<?php

declare(strict_types=1);

use App\Domains\Access\StaffService;
use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Hotel;

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->hotel = Hotel::factory()->create();
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->hotel->update(['owner_user_id' => $this->owner->id]);
    $this->staff = app(StaffService::class);
});

it('lists staff with their roles', function (): void {
    staffMember($this->hotel, SystemRole::Reception, ['first_name' => 'Awa']);

    asStaffOf($this->hotel, $this->owner)
        ->get(route('staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('staff/index')->has('members', 2)->where('isOwner', true));
});

it('lets a manager change a receptionist\'s role, with an audit entry', function (): void {
    $manager = staffMember($this->hotel, SystemRole::Manager);
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $manager)
        ->put(route('staff.role', $reception), ['role_id' => systemRole($this->hotel, SystemRole::ReadOnly)->id]);

    expect($this->staff->roleOf($this->hotel, $reception)?->name)->toBe('read_only')
        ->and(AuditLog::query()->where('event', 'staff.role_changed')->exists())->toBeTrue();
});

it('does not let people change their own role', function (): void {
    $manager = staffMember($this->hotel, SystemRole::Manager);

    asStaffOf($this->hotel, $manager)
        ->put(route('staff.role', $manager), ['role_id' => systemRole($this->hotel, SystemRole::Owner)->id]);

    expect($this->staff->roleOf($this->hotel, $manager)?->name)->toBe('manager');
});

it('only lets owners grant or remove the Owner role', function (): void {
    $manager = staffMember($this->hotel, SystemRole::Manager);
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $manager)
        ->put(route('staff.role', $reception), ['role_id' => systemRole($this->hotel, SystemRole::Owner)->id]);
    expect($this->staff->roleOf($this->hotel, $reception)?->name)->toBe('reception');

    asStaffOf($this->hotel, $this->owner)
        ->put(route('staff.role', $reception), ['role_id' => systemRole($this->hotel, SystemRole::Owner)->id]);
    expect($this->staff->roleOf($this->hotel, $reception)?->name)->toBe('owner');
});

it('never leaves a hotel without an active owner', function (): void {
    $coOwner = staffMember($this->hotel, SystemRole::Owner);

    // The co-owner cannot remove or demote the primary owner.
    asStaffOf($this->hotel, $coOwner)->delete(route('staff.destroy', $this->owner));
    asStaffOf($this->hotel, $coOwner)->put(route('staff.role', $this->owner), ['role_id' => systemRole($this->hotel, SystemRole::Manager)->id]);

    expect($this->staff->isOwner($this->hotel, $this->owner))->toBeTrue()
        ->and($this->staff->isActiveMember($this->hotel, $this->owner))->toBeTrue();

    // The primary owner can demote the co-owner, after which they are the last owner.
    asStaffOf($this->hotel, $this->owner)->put(route('staff.role', $coOwner), ['role_id' => systemRole($this->hotel, SystemRole::Manager)->id]);
    expect($this->staff->activeOwnerCount($this->hotel))->toBe(1);
});

it('suspends, restores and removes staff', function (): void {
    $reception = staffMember($this->hotel, SystemRole::Reception);
    $as = asStaffOf($this->hotel, $this->owner);

    $as->post(route('staff.suspend', $reception));
    expect($this->staff->isActiveMember($this->hotel, $reception))->toBeFalse();

    $as->post(route('staff.reactivate', $reception));
    expect($this->staff->isActiveMember($this->hotel, $reception))->toBeTrue();

    $as->delete(route('staff.destroy', $reception));
    expect($this->hotel->members()->whereKey($reception->id)->exists())->toBeFalse()
        ->and($this->staff->roleOf($this->hotel, $reception))->toBeNull();
});

it('transfers the primary owner after password confirmation', function (): void {
    $coOwner = staffMember($this->hotel, SystemRole::Owner);

    asStaffOf($this->hotel, $this->owner)
        ->post(route('staff.primary-owner', $coOwner))
        ->assertRedirect(route('password.confirm'));

    asStaffOf($this->hotel, $this->owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('staff.primary-owner', $coOwner));

    expect($this->hotel->refresh()->owner_user_id)->toBe($coOwner->id);
});

it('returns 404 for people who are not members of the current hotel', function (): void {
    $other = Hotel::factory()->create();
    $outsider = staffMember($other, SystemRole::Reception);

    asStaffOf($this->hotel, $this->owner)->post(route('staff.suspend', $outsider))->assertNotFound();
});
