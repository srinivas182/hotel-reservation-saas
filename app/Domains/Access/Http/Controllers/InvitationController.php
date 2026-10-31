<?php

declare(strict_types=1);

namespace App\Domains\Access\Http\Controllers;

use App\Domains\Access\Exceptions\StaffRuleViolation;
use App\Domains\Access\HotelRoles;
use App\Domains\Access\InvitationService;
use App\Domains\Access\Models\HotelInvitation;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class InvitationController extends Controller
{
    /**
     * Invitations a hotel may send per hour.
     */
    private const HOURLY_LIMIT = 30;

    public function __construct(
        private readonly TenantContext $context,
        private readonly InvitationService $invitations,
        private readonly HotelRoles $roles,
    ) {}

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $hotel = $this->context->hotel();

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role_id' => ['required', 'integer'],
        ]);

        $role = $this->roles->find($hotel, (int) $validated['role_id'])
            ?? throw ValidationException::withMessages(['role_id' => __('Choose a valid role.')]);

        $key = 'invitations:'.$hotel->getKey();

        if (RateLimiter::tooManyAttempts($key, self::HOURLY_LIMIT)) {
            throw ValidationException::withMessages(['email' => __('Too many invitations sent. Try again later.')]);
        }

        try {
            $this->invitations->invite($hotel, $user, (string) $validated['email'], $role);
        } catch (StaffRuleViolation $violation) {
            throw ValidationException::withMessages(['email' => $violation->getMessage()]);
        }

        RateLimiter::hit($key, 3600);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return back();
    }

    public function resend(HotelInvitation $invitation): RedirectResponse
    {
        $this->invitations->resend($this->context->hotel(), $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent again.')]);

        return back();
    }

    public function destroy(HotelInvitation $invitation): RedirectResponse
    {
        $this->invitations->revoke($this->context->hotel(), $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return back();
    }
}
