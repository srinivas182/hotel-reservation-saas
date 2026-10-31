<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware ("platform") for the MCS platform admin area.
 */
final class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isPlatformStaff(), 403);

        return $next($request);
    }
}
