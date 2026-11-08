<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` with one counter per route. The framework's counter is per
 * user or IP across every numerically throttled route, so browsing the
 * website (120 a minute) used up the demo form's 5 an hour, and guests on
 * one hotel Wi-Fi shared limits across unrelated pages (found in S17).
 */
final class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $scope = $route === null ? '' : '|'.($route->getName() ?? implode(',', $route->methods()).' '.$route->uri());

        return parent::resolveRequestSignature($request).$scope;
    }
}
