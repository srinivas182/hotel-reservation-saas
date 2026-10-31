<?php

declare(strict_types=1);

namespace App\Domains\Access;

use App\Domains\Access\Exceptions\StaffRuleViolation;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Audit;
use App\Domains\Tenancy\Enums\MembershipStatus;
use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staff membership and role changes inside a hotel, with the safety rules:
 * nobody changes their own access, only owners grant or remove the Owner
 * role, the primary owner cannot be removed, and a hotel always keeps at
 * least one active owner. Each person holds exactly one role per hotel.
 */
final class StaffService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly HotelMembershipService $memberships,
    ) {}

    public function roleOf(Hotel $hotel, User $user): ?Role
    {
        return $this->context->run($hotel, function () use ($user): ?Role {
            $user->unsetRelation('roles');

            /** @var Role|null $role */
            $role = $user->roles()->first();

            return $role;
        });
    }

    public function isOwner(Hotel $hotel, User $user): bool
    {
        return $this->roleOf($hotel, $user)?->isOwner() === true;
    }

    /**
     * Add (or re-add) a person to the hotel with a role. Used by invitations,
     * platform admin and seeding; no actor rules apply.
     */
    public function assign(Hotel $hotel, User $user, Role $role): void
    {
        $this->guardRoleBelongsTo($hotel, $role);

        DB::transaction(function () use ($hotel, $user, $role): void {
            $this->memberships->add($hotel, $user);
            $this->setRole($hotel, $user, $role);
        });
    }

    public function changeRole(Hotel $hotel, User $actor, User $member, Role $role): void
    {
        $this->guardRoleBelongsTo($hotel, $role);
        $this->guardNotSelf($actor, $member);

        $current = $this->roleOf($hotel, $member);

        if (($role->isOwner() || $current?->isOwner() === true) && ! $this->isOwner($hotel, $actor)) {
            throw StaffRuleViolation::ownersOnly();
        }

        if ($current?->isOwner() === true && ! $role->isOwner()) {
            $this->guardNotPrimaryOwner($hotel, $member);
            $this->guardAnotherOwner($hotel, $member);
        }

        $this->setRole($hotel, $member, $role);

        Audit::record('staff.role_changed', 'Staff role changed', subject: $hotel, properties: [
            'user_id' => $member->getKey(),
            'from' => $current?->name,
            'to' => $role->name,
        ]);
    }

    public function suspend(Hotel $hotel, User $actor, User $member): void
    {
        $this->guardRemovable($hotel, $actor, $member);
        $this->memberships->suspend($hotel, $member);
    }

    public function reactivate(Hotel $hotel, User $actor, User $member): void
    {
        $this->guardNotSelf($actor, $member);
        $this->memberships->add($hotel, $member);
    }

    public function remove(Hotel $hotel, User $actor, User $member): void
    {
        $this->guardRemovable($hotel, $actor, $member);

        DB::transaction(function () use ($hotel, $member): void {
            $this->context->run($hotel, function () use ($member): void {
                $member->unsetRelation('roles');
                $member->syncRoles([]);
            });

            $this->memberships->remove($hotel, $member);
        });
    }

    public function makePrimaryOwner(Hotel $hotel, User $actor, User $member): void
    {
        if (! $this->isOwner($hotel, $actor)) {
            throw StaffRuleViolation::ownersOnly();
        }

        if (! $this->isOwner($hotel, $member) || ! $this->isActiveMember($hotel, $member)) {
            throw StaffRuleViolation::notOwner();
        }

        $previous = $hotel->owner_user_id;
        $hotel->update(['owner_user_id' => $member->getKey()]);

        Audit::record('staff.primary_owner_changed', 'Primary owner changed', subject: $hotel, properties: [
            'from' => $previous,
            'to' => $member->getKey(),
        ]);
    }

    public function activeOwnerCount(Hotel $hotel): int
    {
        return $hotel->activeMembers()
            ->get()
            ->filter(fn (User $user): bool => $this->isOwner($hotel, $user))
            ->count();
    }

    public function isActiveMember(Hotel $hotel, User $user): bool
    {
        return $hotel->members()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->whereKey($user->getKey())
            ->exists();
    }

    private function setRole(Hotel $hotel, User $user, Role $role): void
    {
        $this->context->run($hotel, function () use ($user, $role): void {
            $user->unsetRelation('roles');
            $user->syncRoles([$role]);
        });
    }

    private function guardRemovable(Hotel $hotel, User $actor, User $member): void
    {
        $this->guardNotSelf($actor, $member);

        if ($this->isOwner($hotel, $member)) {
            if (! $this->isOwner($hotel, $actor)) {
                throw StaffRuleViolation::ownersOnly();
            }

            $this->guardNotPrimaryOwner($hotel, $member);
            $this->guardAnotherOwner($hotel, $member);
        }
    }

    private function guardNotSelf(User $actor, User $member): void
    {
        if ($actor->is($member)) {
            throw StaffRuleViolation::selfChange();
        }
    }

    private function guardNotPrimaryOwner(Hotel $hotel, User $member): void
    {
        if ($hotel->owner_user_id === $member->getKey()) {
            throw StaffRuleViolation::primaryOwner();
        }
    }

    private function guardAnotherOwner(Hotel $hotel, User $member): void
    {
        $others = $hotel->activeMembers()
            ->get()
            ->reject(fn (User $user): bool => $user->is($member))
            ->filter(fn (User $user): bool => $this->isOwner($hotel, $user));

        if ($others->isEmpty()) {
            throw StaffRuleViolation::lastOwner();
        }
    }

    private function guardRoleBelongsTo(Hotel $hotel, Role $role): void
    {
        abort_unless($role->hotel_id === $hotel->getKey(), 404);
    }
}
