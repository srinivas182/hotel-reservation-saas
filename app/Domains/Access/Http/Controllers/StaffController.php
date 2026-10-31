<?php

declare(strict_types=1);

namespace App\Domains\Access\Http\Controllers;

use App\Domains\Access\Exceptions\StaffRuleViolation;
use App\Domains\Access\HotelRoles;
use App\Domains\Access\Models\HotelInvitation;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Permission;
use App\Domains\Access\StaffService;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class StaffController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffService $staff,
        private readonly HotelRoles $roles,
    ) {}

    public function index(#[CurrentUser] User $user): Response
    {
        $hotel = $this->context->hotel();
        $roles = $this->roles->all($hotel);

        $members = $hotel->members()
            ->orderBy('first_name')
            ->get()
            ->map(function (User $member) use ($hotel): array {
                $role = $this->staff->roleOf($hotel, $member);

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'status' => $member->getRelationValue('membership')?->getAttribute('status')?->value,
                    'role_id' => $role?->id,
                    'role_name' => $role?->displayName(),
                    'is_owner' => $role?->isOwner() === true,
                    'is_primary_owner' => $member->id === $hotel->owner_user_id,
                    'last_login_at' => $member->last_login_at?->toIso8601String(),
                ];
            })
            ->values();

        $invitations = HotelInvitation::query()
            ->with('role')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest()
            ->get()
            ->map(fn (HotelInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_name' => $invitation->role?->displayName(),
                'status' => $invitation->status(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ])
            ->values();

        $isOwner = $this->staff->isOwner($hotel, $user);

        return Inertia::render('staff/index', [
            'members' => $members,
            'invitations' => $invitations,
            'roles' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->displayName(),
                'is_owner' => $role->isOwner(),
            ])->values(),
            'currentUserId' => $user->id,
            'isOwner' => $isOwner,
            'can' => [
                'invite' => $user->can(Permission::StaffInvite->value),
                'manage' => $user->can(Permission::StaffManage->value),
                'manageRoles' => $user->can(Permission::RolesManage->value),
            ],
        ]);
    }

    public function updateRole(Request $request, User $member, #[CurrentUser] User $user): RedirectResponse
    {
        $hotel = $this->context->hotel();
        $this->ensureMember($member);

        $validated = $request->validate(['role_id' => ['required', 'integer']]);
        $role = $this->roles->find($hotel, (int) $validated['role_id']) ?? throw ValidationException::withMessages([
            'role_id' => __('Choose a valid role.'),
        ]);

        return $this->attempt(fn () => $this->staff->changeRole($hotel, $user, $member, $role), __('Role updated.'));
    }

    public function suspend(User $member, #[CurrentUser] User $user): RedirectResponse
    {
        $this->ensureMember($member);

        return $this->attempt(fn () => $this->staff->suspend($this->context->hotel(), $user, $member), __('Access suspended.'));
    }

    public function reactivate(User $member, #[CurrentUser] User $user): RedirectResponse
    {
        $this->ensureMember($member);

        return $this->attempt(fn () => $this->staff->reactivate($this->context->hotel(), $user, $member), __('Access restored.'));
    }

    public function destroy(User $member, #[CurrentUser] User $user): RedirectResponse
    {
        $this->ensureMember($member);

        return $this->attempt(fn () => $this->staff->remove($this->context->hotel(), $user, $member), __('Removed from the hotel.'));
    }

    public function makePrimaryOwner(User $member, #[CurrentUser] User $user): RedirectResponse
    {
        $this->ensureMember($member);

        return $this->attempt(fn () => $this->staff->makePrimaryOwner($this->context->hotel(), $user, $member), __('Primary owner changed.'));
    }

    private function ensureMember(User $member): void
    {
        abort_unless($this->context->hotel()->members()->whereKey($member->getKey())->exists(), 404);
    }

    private function attempt(Closure $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (StaffRuleViolation $violation) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $violation->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $success]);

        return back();
    }
}
