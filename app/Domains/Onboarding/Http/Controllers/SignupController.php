<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Http\Controllers;

use App\Domains\Onboarding\Models\CountryTemplate;
use App\Domains\Onboarding\Models\LegalDocument;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Onboarding\Signups;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public sign-up page for hotel owners.
 */
final class SignupController extends Controller
{
    public function show(Request $request): Response
    {
        $locale = app()->getLocale();
        $mode = SignupSettings::current()->mode;

        return Inertia::render('auth/signup', [
            'mode' => $mode,
            'code' => (string) $request->query('code', ''),
            'countries' => CountryTemplate::query()->where('active', true)->get()
                ->map(fn (CountryTemplate $c): array => ['code' => $c->country_code, 'name' => $c->localizedName($locale)])
                ->sortBy('name')->values(),
            'legal' => collect(LegalDocument::KINDS)->mapWithKeys(fn (string $k): array => [$k => route('legal.show', $k)]),
        ]);
    }

    public function store(Request $request, Signups $signups): RedirectResponse
    {
        // Spam trap: real people never fill this hidden field.
        if (trim((string) $request->input('website', '')) !== '') {
            return to_route('signup')->with('status', 'check-email');
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:191', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['required', 'string', 'max:25'],
            'hotel_name' => ['required', 'string', 'max:120'],
            'country_code' => ['required', 'string', 'size:2', Rule::exists('country_templates', 'country_code')->where('active', true)],
            'city' => ['required', 'string', 'max:120'],
            'rooms' => ['required', 'integer', 'min:1', 'max:2000'],
            'code' => ['nullable', 'string', 'max:40'],
            'accept_terms' => ['accepted'],
            'accept_dpa' => ['accepted'],
        ]);

        $user = $signups->register($data, $request->ip());
        Auth::login($user);
        event(new Registered($user));

        return to_route('verification.notice');
    }
}
