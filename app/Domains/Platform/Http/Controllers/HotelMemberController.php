<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Access\HotelRoles;
use App\Domains\Access\StaffService;
use App\Domains\Access\SystemRole;
use App\Domains\Platform\HotelPeople;
use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Adds people to a hotel (an existing account, or a new one created with the
 * name and password the admin gives) and removes them.
 */
final class HotelMemberController extends Controller
{
    public function store(Request $request, Hotel $hotel, StaffService $staff, HotelRoles $roles, HotelPeople $people): RedirectResponse
    {
        Gate::authorize('manageMembers', $hotel);

        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['nullable', Rule::enum(SystemRole::class)],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:200'],
        ]);

        // 0.31.3: a new e-mail creates the account with the name and password given.
        $user = $people->findOrCreate(
            (string) $validated['email'],
            $validated['first_name'] ?? null,
            $validated['last_name'] ?? null,
            $validated['password'] ?? null,
            $hotel->default_locale,
            $request->user(),
        );
        $role = SystemRole::tryFrom((string) ($validated['role'] ?? '')) ?? SystemRole::Reception;
        $staff->assign($hotel, $user, $roles->system($hotel, $role));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added to the hotel.', ['name' => $user->name])]);

        return to_route('platform.hotels.edit', $hotel);
    }

    public function destroy(Hotel $hotel, User $user, TenantContext $context, HotelMembershipService $memberships): RedirectResponse
    {
        Gate::authorize('manageMembers', $hotel);

        abort_if($user->getKey() === $hotel->owner_user_id, 422, 'Change the hotel owner before removing them.');

        $context->run($hotel, function () use ($user): void {
            $user->unsetRelation('roles');
            $user->syncRoles([]);
        });
        $memberships->remove($hotel, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed from the hotel.', ['name' => $user->name])]);

        return to_route('platform.hotels.edit', $hotel);
    }
}
