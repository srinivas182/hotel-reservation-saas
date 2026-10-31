<?php

declare(strict_types=1);

use App\Domains\Audit\Http\Middleware\AssignRequestId;
use App\Domains\Identity\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Domains\Tenancy\Http\Middleware\EnsureHotelSelected;
use App\Domains\Tenancy\Http\Middleware\ResolveCurrentHotel;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
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

        $middleware->web(append: [
            HandleAppearance::class,
            ResolveCurrentHotel::class,
            EnsureTwoFactorEnrolled::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'hotel' => EnsureHotelSelected::class,
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
    })->create();
