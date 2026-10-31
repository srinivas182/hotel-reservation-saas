<?php

declare(strict_types=1);

use App\Domains\Access\HotelRoles;
use App\Domains\Access\Models\Role;
use App\Domains\Access\StaffService;
use App\Domains\Access\SystemRole;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;

function systemRole(Hotel $hotel, SystemRole $role): Role
{
    return app(HotelRoles::class)->system($hotel, $role);
}

/**
 * A staff member of the hotel with the given system role. Owners get
 * two-factor set up so they are not redirected to the security page.
 */
function staffMember(Hotel $hotel, SystemRole $role = SystemRole::Reception, array $attributes = []): User
{
    $factory = User::factory();

    if ($role === SystemRole::Owner) {
        $factory = $factory->withTwoFactor();
    }

    $user = $factory->create($attributes);
    app(StaffService::class)->assign($hotel, $user, systemRole($hotel, $role));

    return $user;
}

function asStaffOf(Hotel $hotel, User $user): Tests\TestCase
{
    return test()->actingAs($user)->withSession(['current_hotel_id' => $hotel->id]);
}
