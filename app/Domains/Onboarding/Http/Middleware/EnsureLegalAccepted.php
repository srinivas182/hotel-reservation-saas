<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Http\Middleware;

use App\Domains\Onboarding\Legal;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users must accept the current legal texts (again after a new version)
 * before continuing.
 */
final class EnsureLegalAccepted
{
    private const SKIP = ['legal.*', 'logout', 'verification.*', 'two-factor.*', 'password.*', 'signup*', 'webhooks.*', 'payments.*', 'quotes.*', 'menu.*', 'health', 'csp.report', 'invitations.*'];

    public function __construct(private readonly Legal $legal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || Str::is(self::SKIP, (string) $request->route()?->getName()) || $this->legal->missing($user) === []) {
            return $next($request);
        }

        return $request->expectsJson() ? response()->json(['message' => 'Legal texts must be accepted.'], 409) : redirect()->route('legal.accept');
    }
}
