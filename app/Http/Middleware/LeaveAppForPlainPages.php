<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the app asks for a page that is not an app screen (the website, the
 * help centre, the status page, legal texts, Horizon) — after logging out,
 * say, or through a redirect — the browser is sent there with a full page
 * load. Without this, Inertia shows the page inside a pop-up over the app.
 */
final class LeaveAppForPlainPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->header('X-Inertia') !== null
            && $request->isMethod('GET')
            && ! $response->headers->has('X-Inertia')
            && $response->isSuccessful()
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return Inertia::location($request->fullUrl());
        }

        return $response;
    }
}
