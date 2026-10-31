<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every web response.
 *
 * The Content Security Policy starts in report-only mode: violations are
 * reported to /csp-report and logged, nothing is blocked. It is switched to
 * enforcing once reports are clean (config: security.csp.enforce).
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $headerName = config('security.csp.enforce') === true
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        $headers->set($headerName, $this->contentSecurityPolicy());

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();
        $devServer = app()->environment('local') ? ' http://localhost:5173 ws://localhost:5173' : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$devServer}",
            "style-src 'self' 'unsafe-inline'{$devServer}",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'{$devServer}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            'report-uri '.route('csp.report', absolute: false),
        ]);
    }
}
