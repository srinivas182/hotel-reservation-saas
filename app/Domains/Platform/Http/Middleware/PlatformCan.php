<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Platform\PlatformAbility;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `platform.can:ability` — the platform user's role must allow the ability.
 */
final class PlatformCan
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->platformCan(PlatformAbility::from($ability)), 403);

        return $next($request);
    }
}
