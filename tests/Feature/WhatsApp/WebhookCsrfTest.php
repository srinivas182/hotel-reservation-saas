<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/*
 * Tests run without CSRF protection, so this checks the routes themselves:
 * calls from Meta and browsers' CSP reports carry no CSRF token and must
 * not be blocked (Laravel 13's CSRF middleware is PreventRequestForgery).
 * Found by the S8.3 load smoke test, which runs the real middleware.
 */
it('accepts calls from Meta and CSP reports without a CSRF token', function (string $name): void {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route->excludedMiddleware())->toContain(PreventRequestForgery::class);
})->with(['webhooks.whatsapp.receive', 'csp.report']);
