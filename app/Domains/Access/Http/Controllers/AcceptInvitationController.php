<?php

declare(strict_types=1);

namespace App\Domains\Access\Http\Controllers;

use App\Domains\Access\InvitationService;
use App\Domains\Tenancy\Http\Middleware\ResolveCurrentHotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public page reached from an invitation email.
 *
 * - New person: chooses a name and password; the email counts as verified.
 * - Existing account: signs in (if needed) and accepts.
 */
final class AcceptInvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitations->findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return Inertia::render('invitations/show', [
                'state' => $invitation === null ? 'invalid' : $invitation->status(),
                'token' => $token,
            ]);
        }

        $accountExists = User::query()->where('email', $invitation->email)->exists();
        $user = $request->user();

        return Inertia::render('invitations/show', [
            'state' => 'pending',
            'token' => $token,
            'invitation' => [
                'hotel' => $invitation->hotel?->name,
                'role' => $invitation->role?->displayName(),
                'email' => $invitation->email,
            ],
            'accountExists' => $accountExists,
            'signedInAs' => $user instanceof User ? $user->email : null,
            'signedInAsInvitee' => $user instanceof User && Str::lower($user->email) === $invitation->email,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->findByToken($token);

        abort_if($invitation === null || ! $invitation->isPending(), 410);

        $user = $request->user();

        if ($user instanceof User) {
            abort_unless(Str::lower($user->email) === $invitation->email, 403);
        } else {
            abort_if(User::withTrashed()->where('email', $invitation->email)->exists(), 409, __('Sign in to accept this invitation.'));

            $validated = $request->validate([
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);

            $user = DB::transaction(fn (): User => User::query()->forceCreate([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $invitation->email,
                'password' => $validated['password'],
                'email_verified_at' => now(),
            ]));

            Auth::login($user);
            $request->session()->regenerate();
        }

        $this->invitations->accept($invitation, $user);

        $request->session()->put(ResolveCurrentHotel::SESSION_KEY, $invitation->hotel_id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Welcome to :hotel.', ['hotel' => $invitation->hotel?->name])]);

        return to_route('dashboard');
    }
}
