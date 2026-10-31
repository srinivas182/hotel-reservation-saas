<?php

declare(strict_types=1);

namespace App\Domains\Platform\Policies;

use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;

/**
 * Platform-level permissions on hotels. Support staff can view; only super
 * admins can create, change or manage members. Hotel-side permissions
 * (owners editing their own hotel) arrive with hotel roles in S1.
 */
final class HotelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformStaff();
    }

    public function view(User $user, Hotel $hotel): bool
    {
        return $user->isPlatformStaff();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Hotel $hotel): bool
    {
        return $user->isSuperAdmin();
    }

    public function manageMembers(User $user, Hotel $hotel): bool
    {
        return $user->isSuperAdmin();
    }
}
