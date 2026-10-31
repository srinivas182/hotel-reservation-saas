<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Middleware;

use App\Domains\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware ("hotel") for pages that work on hotel-owned data.
 */
final class EnsureHotelSelected
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->context->has(), 403, 'Select a hotel to continue.');

        return $next($request);
    }
}
