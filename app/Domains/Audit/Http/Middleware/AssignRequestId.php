<?php

declare(strict_types=1);

namespace App\Domains\Audit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an ID that appears in logs, audit entries and the
 * X-Request-Id response header. A well-formed incoming ID (for example from
 * a load balancer) is kept so a request can be traced end to end.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Request-Id');

        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
