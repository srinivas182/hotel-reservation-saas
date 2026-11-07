<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Middleware;

use App\Domains\Billing\Entitlements;
use App\Domains\Billing\Module;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages of a module the hotel's plan does not include are not available
 * (the module's data is kept).
 */
final class RequireModule
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->entitlements->has(Module::from($module)), 403, (string) __('Your plan does not include this module.'));

        return $next($request);
    }
}
