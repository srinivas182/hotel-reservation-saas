<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Middleware;

use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out the current hotel for a signed-in user from the session.
 *
 * - The stored hotel must be one the user is an active member of, and the
 *   hotel must be open for access; otherwise the request is refused (403)
 *   and the stale selection is cleared.
 * - With nothing stored, the user's first accessible hotel is selected.
 * - Users with no hotels (for example platform staff) continue without one;
 *   routes that need a hotel use the "hotel" middleware.
 */
final class ResolveCurrentHotel
{
    public const SESSION_KEY = 'current_hotel_id';

    public function __construct(
        private readonly TenantContext $context,
        private readonly HotelMembershipService $memberships,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Start every request without a hotel (long-lived workers, tests).
        $this->context->forget();

        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        Context::add('user_id', $user->getKey());

        $storedId = $request->session()->get(self::SESSION_KEY);

        if (is_string($storedId)) {
            $hotel = $this->memberships->accessibleHotel($user, $storedId);

            if ($hotel === null) {
                $request->session()->forget(self::SESSION_KEY);
                abort(403, 'You no longer have access to this hotel.');
            }
        } else {
            $hotel = $this->memberships->defaultHotelFor($user);

            if ($hotel !== null) {
                $request->session()->put(self::SESSION_KEY, $hotel->getKey());
            }
        }

        if ($hotel !== null) {
            $this->context->set($hotel);
        }

        // Roles and permissions depend on the current hotel; never reuse ones
        // loaded for another hotel (Octane workers, tests, hotel switching).
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $next($request);
    }
}
