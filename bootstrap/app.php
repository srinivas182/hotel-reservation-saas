<?php

declare(strict_types=1);

use App\Domains\Audit\Http\Middleware\AssignRequestId;
use App\Domains\Identity\Http\Middleware\EnsureAccountActive;
use App\Domains\Identity\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Domains\Identity\Http\Middleware\SetLocale;
use App\Domains\Platform\Http\Middleware\EnsurePlatformStaff;
use App\Domains\Tenancy\Http\Middleware\EnsureHotelSelected;
use App\Domains\Tenancy\Http\Middleware\ResolveCurrentHotel;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->prepend(AssignRequestId::class);

        // Behind Caddy and Nginx on the Docker network; trust their forwarded headers.
        $middleware->trustProxies(at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1']);

        $middleware->web(append: [
            SecurityHeaders::class,
            HandleAppearance::class,
            ResolveCurrentHotel::class,
            SetLocale::class,
            EnsureAccountActive::class,
            EnsureTwoFactorEnrolled::class,
            App\Domains\Onboarding\Http\Middleware\EnsureLegalAccepted::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'module' => App\Domains\Billing\Http\Middleware\RequireModule::class,
            'reduced' => App\Domains\Billing\Http\Middleware\ReducedMode::class,
            'hotel' => EnsureHotelSelected::class,
            'platform' => EnsurePlatformStaff::class,
            'platform.can' => App\Domains\Platform\Http\Middleware\PlatformCan::class,
            // One rate-limit counter per route, not one shared by every throttled route (S17).
            'throttle' => App\Http\Middleware\ThrottlePerRoute::class,
        ]);

        // The current hotel must be known before route-model binding runs,
        // so hotel-owned models in URLs are resolved inside the hotel scope.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveCurrentHotel::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureHotelSelected::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Error tracking (optional): active only when SENTRY_LARAVEL_DSN is set.
        Sentry\Laravel\Integration::handles($exceptions);
    })->create();
