<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Hotel\Support\Locales;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the interface language for the request:
 * the user's own choice, then the current hotel's default language,
 * then the browser's preferred language, then English.
 */
final class SetLocale
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $supported = Locales::codes();
        $user = $request->user();

        if ($user instanceof User && in_array($user->preferred_locale, $supported, true)) {
            return (string) $user->preferred_locale;
        }

        $sessionLocale = $request->hasSession() ? $request->session()->get('locale') : null;
        if (is_string($sessionLocale) && in_array($sessionLocale, $supported, true)) {
            return $sessionLocale;
        }

        if ($this->context->has() && in_array($this->context->hotel()->default_locale, $supported, true)) {
            return $this->context->hotel()->default_locale;
        }

        return $request->getPreferredLanguage($supported) ?? 'en';
    }
}
