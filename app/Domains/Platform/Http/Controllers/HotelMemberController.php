<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\Models\Hotel;
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
    public function store(Request $request, Hotel $hotel, HotelMembershipService $memberships): RedirectResponse
    {
        Gate::authorize('manageMembers', $hotel);

        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')->whereNull('deleted_at')],
        ], [
            'email.exists' => __('No user with this email exists.'),
        ]);

        $user = User::query()->where('email', $validated['email'])->firstOrFail();
        $memberships->add($hotel, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added to the hotel.', ['name' => $user->name])]);

        return to_route('platform.hotels.edit', $hotel);
    }

    public function destroy(Hotel $hotel, User $user, HotelMembershipService $memberships): RedirectResponse
    {
        Gate::authorize('manageMembers', $hotel);

        abort_if($user->getKey() === $hotel->owner_user_id, 422, 'Change the hotel owner before removing them.');

        $memberships->remove($hotel, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed from the hotel.', ['name' => $user->name])]);

        return to_route('platform.hotels.edit', $hotel);
    }
}
