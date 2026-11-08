<?php

declare(strict_types=1);

use App\Domains\Access\Permission;
use App\Domains\Access\SystemRole;
use App\Domains\Tenancy\Models\Hotel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Access/helpers.php';

/**
 * Hotel routes allowed without a `can:` middleware, and why.
 */
const HOTEL_ROUTES_WITHOUT_PERMISSION = [
    'hotel.announcements.dismiss' => 'Each staff member hides a banner for themselves only.',
    'hotel.my-alerts' => 'Personal alert settings of the signed-in staff member.',
    'hotel.my-alerts.update' => 'Personal alert settings of the signed-in staff member.',
    'hotel.my-alerts.test' => 'Sends a test alert to the signed-in staff member only.',
    'hotel.folio-lines.correct' => 'Checked in the controller: payment lines need folio.payments, others folio.charge.',
];

/**
 * @return list<RoutingRoute>
 */
function hotelRoutes(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r): bool => in_array('hotel', $r->gatherMiddleware(), true)));
}

function routePermission(RoutingRoute $route): ?string
{
    foreach ($route->gatherMiddleware() as $m) {
        if (is_string($m) && str_starts_with($m, 'can:')) {
            return substr($m, 4);
        }
    }

    return null;
}

it('checks a permission on every hotel route, except a short reviewed list', function (): void {
    $unguarded = collect(hotelRoutes())->filter(fn (RoutingRoute $r): bool => routePermission($r) === null)->map(fn (RoutingRoute $r): string => (string) $r->getName())->sort()->values()->all();

    expect($unguarded)->toBe(collect(array_keys(HOTEL_ROUTES_WITHOUT_PERMISSION))->sort()->values()->all());
});

it('only names permissions that exist', function (): void {
    $unknown = collect(hotelRoutes())->map(fn (RoutingRoute $r): ?string => routePermission($r))->filter()
        ->reject(fn (string $p): bool => Permission::tryFrom(explode(',', $p)[0]) !== null)->unique()->values()->all();

    expect($unknown)->toBe([]);
});

it('opens each hotel screen exactly to the roles that hold its permission', function (SystemRole $role): void {
    $hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $user = staffMember($hotel, $role);
    $held = array_map(fn (Permission $p): string => $p->value, $role->permissions());
    $wrong = [];

    foreach (hotelRoutes() as $route) {
        $permission = routePermission($route);
        if ($permission === null || ! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) {
            continue;
        }

        $status = asStaffOf($hotel, $user)->get('/'.ltrim($route->uri(), '/'))->getStatusCode();
        $allowed = in_array(explode(',', $permission)[0], $held, true);

        if ($allowed === ($status === 403)) {
            $wrong[] = "{$route->getName()} ({$permission}): {$status}";
        }
    }

    expect($wrong)->toBe([]);
})->with(SystemRole::cases());
