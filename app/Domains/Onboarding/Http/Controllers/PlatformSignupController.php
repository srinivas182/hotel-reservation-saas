<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Onboarding\Models\CountryTemplate;
use App\Domains\Onboarding\Models\LegalDocument;
use App\Domains\Onboarding\Models\PendingSignup;
use App\Domains\Onboarding\Models\SignupCode;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform → Sign-ups: who signed up, invitation codes, the sign-up
 * settings, country templates and legal texts.
 */
final class PlatformSignupController extends Controller
{
    public function index(): Response
    {
        $locale = app()->getLocale();

        return Inertia::render('platform/signups', [
            'settings' => SignupSettings::current()->only(['mode', 'approval_required', 'block_disposable_emails', 'emails_enabled', 'notify_emails']),
            'codes' => SignupCode::query()->latest('id')->get()->map(fn (SignupCode $c): array => [
                'id' => $c->id, 'code' => $c->code, 'note' => $c->note, 'uses' => $c->uses, 'max_uses' => $c->max_uses,
                'expires_at' => $c->expires_at?->toDateString(), 'usable' => $c->usable(), 'revoked' => $c->revoked_at !== null,
            ])->values(),
            'signups' => PendingSignup::query()->latest('id')->limit(200)->get()->map(function (PendingSignup $p): array {
                $user = User::query()->find($p->user_id);
                $hotel = $p->hotel_id === null ? null : Hotel::query()->find($p->hotel_id);
                $trialEnds = $hotel === null ? null : Subscription::query()->withoutGlobalScopes()->where('hotel_id', $hotel->id)->value('trial_ends_at');

                return [
                    'id' => $p->id,
                    'hotel' => $hotel === null ? $p->details['hotel_name'] : $hotel->name,
                    'hotel_id' => $hotel?->id,
                    'country' => $p->details['country_code'],
                    'city' => $p->details['city'],
                    'rooms' => $p->details['rooms'],
                    'owner' => $user?->name,
                    'email' => $user?->email,
                    'verified' => $user?->hasVerifiedEmail() === true,
                    'status' => $hotel?->status->value,
                    'trial_ends' => $trialEnds === null ? null : substr((string) $trialEnds, 0, 10),
                    'signed_up' => $p->created_at?->toDateString(),
                    ...($hotel === null ? ['setup' => null, 'golive' => null] : app(\App\Domains\Tenancy\TenantContext::class)->run($hotel, function (): array {
                        $progress = \App\Domains\Onboarding\Models\OnboardingProgress::current();

                        return [
                            'setup' => app(\App\Domains\Onboarding\SetupChecklist::class)->summary(),
                            'golive' => ['requested_at' => $progress->golive_requested_at?->toDateTimeString(), 'decision' => $progress->golive_decision],
                        ];
                    })),
                ];
            })->values(),
            'funnel' => \App\Domains\Onboarding\Funnel::build(),
            'templates' => CountryTemplate::query()->orderBy('country_code')->get()->map(fn (CountryTemplate $t): array => [
                'id' => $t->id, 'country_code' => $t->country_code, 'name' => $t->localizedName($locale), 'name_fr' => $t->name['fr'] ?? '', 'name_en' => $t->name['en'] ?? '',
                'currency' => $t->currency, 'timezone' => $t->timezone, 'locale' => $t->locale, 'check_in' => $t->check_in, 'check_out' => $t->check_out,
                'taxes' => array_map(fn (array $r): array => ['name' => $r['name'], 'rate' => $r['rate'], 'inclusive' => $r['inclusive']], $t->tax_rules),
                'categories' => implode(', ', $t->category_suggestions ?? []), 'active' => $t->active,
            ])->values(),
            'legal' => collect(LegalDocument::KINDS)->map(function (string $kind): array {
                $current = LegalDocument::current($kind);

                return ['kind' => $kind, 'label' => LegalDocument::label($kind), 'version' => $current?->version, 'fr' => $current?->body['fr'] ?? '', 'en' => $current?->body['en'] ?? '', 'url' => route('legal.show', $kind)];
            })->values(),
        ]);
    }

    public function decideGoLive(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'reason' => ['nullable', 'required_if:approve,false', 'string', 'max:500']]);

        try {
            app(\App\Domains\Onboarding\GoLive::class)->decide($hotel, (bool) $data['approve'], $data['reason'] ?? null, $user);
        } catch (\RuntimeException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['golive' => $e->getMessage()]);
        }

        return back();
    }

    public function settings(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['open', 'code', 'closed'])],
            'approval_required' => ['required', 'boolean'],
            'block_disposable_emails' => ['required', 'boolean'],
            'emails_enabled' => ['required', 'boolean'],
            'notify_emails' => ['array', 'max:10'],
            'notify_emails.*' => ['email', 'max:191'],
        ]);
        SignupSettings::current()->update($data);
        Audit::record('signup.settings_saved', 'Sign-up settings saved', causer: $user, properties: $data);

        return back();
    }

    public function storeCode(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'alpha_dash', 'max:40', Rule::unique('signup_codes', 'code')],
            'note' => ['nullable', 'string', 'max:200'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);
        $code = SignupCode::query()->create([
            'code' => Str::upper(trim((string) ($data['code'] ?? '')) ?: Str::random(8)),
            'note' => $data['note'] ?? null, 'max_uses' => $data['max_uses'] ?? null,
            'expires_at' => isset($data['expires_at']) ? $data['expires_at'].' 23:59:59' : null, 'created_by' => $user->getKey(),
        ]);
        Audit::record('signup.code_created', 'Invitation code created', causer: $user, properties: ['code' => $code->code]);

        return back();
    }

    public function revokeCode(SignupCode $code, #[CurrentUser] User $user): RedirectResponse
    {
        $code->update(['revoked_at' => now()]);
        Audit::record('signup.code_revoked', 'Invitation code revoked', causer: $user, properties: ['code' => $code->code]);

        return back();
    }

    public function suspend(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['suspend' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:300']]);
        $hotel->forceFill(['status' => (bool) $data['suspend'] ? HotelStatus::Suspended : HotelStatus::Trial])->save();
        Audit::record((bool) $data['suspend'] ? 'signup.suspended' : 'signup.reactivated', (bool) $data['suspend'] ? 'Hotel suspended' : 'Hotel reactivated', subject: $hotel, causer: $user, properties: ['reason' => $data['reason'] ?? null]);

        return back();
    }

    public function saveTemplate(Request $request, #[CurrentUser] User $user, ?CountryTemplate $template = null): RedirectResponse
    {
        $data = $request->validate([
            'country_code' => [$template === null ? 'required' : 'prohibited', 'string', 'size:2', Rule::unique('country_templates', 'country_code')],
            'name_fr' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'locale' => ['required', Rule::in(['fr', 'en'])],
            'check_in' => ['required', 'date_format:H:i'],
            'check_out' => ['required', 'date_format:H:i'],
            'taxes' => ['array', 'max:6'],
            'taxes.*.name' => ['required', 'string', 'max:40'],
            'taxes.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'taxes.*.inclusive' => ['required', 'boolean'],
            'categories' => ['nullable', 'string', 'max:300'],
            'active' => ['required', 'boolean'],
        ]);

        $values = [
            'name' => ['fr' => $data['name_fr'], 'en' => $data['name_en']],
            'currency' => strtoupper((string) $data['currency']), 'timezone' => $data['timezone'], 'locale' => $data['locale'],
            'check_in' => $data['check_in'], 'check_out' => $data['check_out'],
            'tax_rules' => array_values(array_map(fn (array $t): array => ['name' => (string) $t['name'], 'rate' => (float) $t['rate'], 'inclusive' => (bool) $t['inclusive'], 'applies_to' => ['rooms', 'food_beverage', 'events']], (array) ($data['taxes'] ?? []))),
            'category_suggestions' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['categories'] ?? ''))))),
            'active' => (bool) $data['active'],
        ];

        $template === null ? $template = CountryTemplate::query()->create([...$values, 'country_code' => strtoupper((string) $data['country_code'])]) : $template->update($values);
        Audit::record('signup.template_saved', 'Country template saved', causer: $user, properties: ['country' => $template->country_code]);

        return back();
    }

    public function storeTemplate(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveTemplate($request, $user, null);
    }

    public function updateTemplate(Request $request, CountryTemplate $template, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveTemplate($request, $user, $template);
    }

    /**
     * Publish a new version of a legal text (owners accept it at next login).
     */
    public function publishLegal(Request $request, string $kind, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless(in_array($kind, LegalDocument::KINDS, true), 404);
        $data = $request->validate(['fr' => ['required', 'string', 'max:50000'], 'en' => ['required', 'string', 'max:50000']]);
        $version = ((int) LegalDocument::query()->where('kind', $kind)->max('version')) + 1;
        LegalDocument::query()->create(['kind' => $kind, 'version' => $version, 'body' => ['fr' => $data['fr'], 'en' => $data['en']], 'published_at' => now(), 'created_by' => $user->getKey()]);
        Audit::record('legal.published', 'Legal text published', causer: $user, properties: ['kind' => $kind, 'version' => $version]);

        return back();
    }
}
