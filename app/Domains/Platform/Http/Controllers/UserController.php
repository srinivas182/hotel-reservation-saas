<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Identity\Enums\PlatformRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform users: everyone with an account, with deactivation, and
 * invitations for MCS platform staff (super admin or support).
 */
final class UserController extends Controller
{
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->withCount('activeHotels')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('email', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")))
            ->orderBy('first_name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'platform_role' => $row->platform_role?->value,
                'hotels_count' => $row->getAttribute('active_hotels_count'),
                'deactivated' => $row->isDeactivated(),
                'two_factor' => $row->hasConfirmedTwoFactor(),
                'last_login_at' => $row->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('platform/users/index', [
            'users' => $users,
            'filters' => ['search' => $search],
            'roles' => array_map(fn (PlatformRole $role): string => $role->value, PlatformRole::cases()),
            'currentUserId' => $user->id,
            'canManage' => $user->isSuperAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin() === true, 403);

        $request->merge(['email' => Str::lower((string) $request->input('email'))]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'platform_role' => ['required', Rule::enum(PlatformRole::class)],
        ]);

        $staff = DB::transaction(fn (): User => User::query()->forceCreate([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => Str::password(40),
            'platform_role' => $data['platform_role'],
            'email_verified_at' => now(),
        ]));

        // The password-reset email lets the new staff member choose a password.
        Password::broker()->sendResetLink(['email' => $staff->email]);

        Audit::record('platform.staff_invited', 'Platform staff invited', subject: $staff, properties: [
            'platform_role' => $data['platform_role'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return back();
    }

    public function deactivate(User $user, #[CurrentUser] User $actor): RedirectResponse
    {
        abort_unless($actor->isSuperAdmin(), 403);
        abort_if($actor->is($user), 422, __('You cannot deactivate your own account.'));

        $user->forceFill([
            'deactivated_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('sessions')->where('user_id', $user->getKey())->delete();

        Audit::record('platform.user_deactivated', 'User deactivated', subject: $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deactivated.')]);

        return back();
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin() === true, 403);

        $user->forceFill(['deactivated_at' => null])->save();

        Audit::record('platform.user_reactivated', 'User reactivated', subject: $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('User reactivated.')]);

        return back();
    }
}
