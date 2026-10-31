<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hotel owners and platform staff must set up two-factor authentication
 * before they can use the application. Until they do, every page except
 * the security settings, two-factor setup, email verification and sign-out
 * redirects to the security settings page.
 */
final class EnsureTwoFactorEnrolled
{
    /**
     * Route names that stay reachable while two-factor setup is pending.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'security.edit',
        'logout',
        'password.confirm',
        'password.confirmation',
        'password.confirm.store',
        'verification.*',
        'two-factor.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            ! $user instanceof User
            || $user->hasConfirmedTwoFactor()
            || $request->routeIs(...self::ALLOWED_ROUTES)
            || ! $user->requiresTwoFactor()
        ) {
            return $next($request);
        }

        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => __('Set up two-factor authentication to continue.'),
        ]);

        return to_route('security.edit');
    }
}
