<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Public pages that only render a form or static text (no data, no work).
 */
const PUBLIC_WITHOUT_RATE_LIMIT = ['home', 'login', 'signup', 'legal.show', 'verification.notice', 'password.request', 'password.reset', 'two-factor.login', 'logout'];

it('rate limits every public route that does work or shows data', function (): void {
    $missing = collect(Route::getRoutes()->getRoutes())
        // Horizon's group carries web, auth and throttle, plus its super-admin gate.
        ->reject(fn (RoutingRoute $r): bool => collect($r->gatherMiddleware())->contains(fn ($m): bool => is_string($m) && (str_starts_with($m, 'auth') || str_starts_with($m, 'throttle') || $m === 'horizon')))
        ->reject(fn (RoutingRoute $r): bool => in_array($r->getName(), PUBLIC_WITHOUT_RATE_LIMIT, true) || $r->uri() === 'up' || str_starts_with($r->uri(), '_inertia/devtools'))
        ->map(fn (RoutingRoute $r): string => implode('|', $r->methods()).' '.$r->uri())
        ->values()->all();

    expect($missing)->toBe([]);
});

it('never serves the private file store directly', function (): void {
    expect(Route::has('storage.local'))->toBeFalse();
    $this->get('/storage/hotels/x/menu/a.jpg')->assertNotFound();
});

it('keeps developer tools closed', function (): void {
    expect($this->get('/_inertia/devtools/entries')->getStatusCode())->toBeIn([403, 404]);
});

it('keeps a separate rate-limit counter for each route', function (): void {
    // Browsing the website must not use up the demo form's small allowance.
    foreach (range(1, 8) as $i) {
        $this->get('/pricing')->assertOk();
    }

    $this->post('/demo', ['name' => 'A', 'hotel_name' => 'B', 'whatsapp' => '+225 07 00 00 00 01'])->assertRedirect('/demo');
});
