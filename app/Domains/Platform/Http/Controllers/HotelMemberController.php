<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Access\HotelRoles;
use App\Domains\Access\StaffService;
use App\Domains\Access\SystemRole;
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
 * Adds existing users to a hotel and removes them. Proper staff invitations
 * (email invite, role choice) arrive in S1.
 */
final class HotelMemberController extends Controller
{
    public function store(Request $request, Hotel $hotel, StaffService $staff, HotelRoles $roles): RedirectResponse
    {
        Gate::authorize('manageMembers', $hotel);

        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')->whereNull('deleted_at')],
            'role' => ['nullable', Rule::enum(SystemRole::class)],
        ], [
            'email.exists' => __('No user with this email exists.'),
        ]);

        $user = User::query()->where('email', $validated['email'])->firstOrFail();
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
