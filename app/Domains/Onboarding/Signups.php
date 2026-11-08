<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

use App\Domains\Audit\Audit;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Onboarding\Mail\NewSignupMail;
use App\Domains\Onboarding\Models\CountryTemplate;
use App\Domains\Onboarding\Models\LegalAcceptance;
use App\Domains\Onboarding\Models\LegalDocument;
use App\Domains\Onboarding\Models\PendingSignup;
use App\Domains\Onboarding\Models\SignupCode;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Platform\HotelAdministration;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service sign-up: an account and a pending hotel first; the hotel is
 * created from its country template once the email is confirmed.
 */
final class Signups
{
    /** A short list of throwaway email domains (the platform can turn the check off). */
    private const DISPOSABLE = ['mailinator.com', 'yopmail.com', 'guerrillamail.com', 'tempmail.com', 'temp-mail.org', '10minutemail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com', 'throwawaymail.com', 'maildrop.cc'];

    public function __construct(
        private readonly HotelAdministration $hotels,
        private readonly Legal $legal,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, phone: string, hotel_name: string, country_code: string, city: string, rooms: int, code?: string|null}  $data
     */
    public function register(array $data, ?string $ip): User
    {
        $settings = SignupSettings::current();
        $email = Str::lower(trim($data['email']));

        if ($settings->mode === 'closed') {
            abort(403);
        }

        if ($settings->block_disposable_emails && in_array(Str::after($email, '@'), self::DISPOSABLE, true)) {
            throw ValidationException::withMessages(['email' => __('Please use a permanent email address.')]);
        }

        return DB::transaction(function () use ($data, $ip, $settings, $email): User {
            $code = null;

            if ($settings->mode === 'code' || trim((string) ($data['code'] ?? '')) !== '') {
                $code = SignupCode::query()->where('code', Str::upper(trim((string) ($data['code'] ?? ''))))->lockForUpdate()->first();

                if ($code === null || ! $code->usable()) {
                    throw ValidationException::withMessages(['code' => __('This invitation code is not valid.')]);
                }

                $code->increment('uses');
                // A lead turned into a trial (S17).
                \App\Domains\Site\Models\Lead::query()->where('signup_code_id', $code->id)->whereIn('status', ['new', 'contacted', 'demo_done'])->update(['status' => 'trial']);
            }

            $user = User::query()->forceCreate([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'email' => $email,
                'phone_e164' => \App\Domains\Bookings\GuestDirectory::normalisePhone($data['phone']),
                'password' => Hash::make($data['password']),
                'preferred_locale' => CountryTemplate::query()->where('country_code', $data['country_code'])->value('locale') ?? 'fr',
            ]);

            PendingSignup::query()->create([
                'user_id' => $user->getKey(),
                'details' => ['hotel_name' => trim($data['hotel_name']), 'country_code' => $data['country_code'], 'city' => trim($data['city']), 'phone' => trim($data['phone']), 'rooms' => (int) $data['rooms']],
                'signup_code_id' => $code?->id,
                'ip' => $ip,
            ]);

            $this->legal->accept($user, array_values(array_filter(array_map(fn (string $kind): ?LegalDocument => LegalDocument::current($kind), LegalDocument::KINDS))), $ip);
            Audit::record('signup.registered', 'Self sign-up registered', causer: $user, properties: ['country' => $data['country_code'], 'code' => $code?->code]);

            return $user;
        });
    }

    /**
     * A hotel from its country template, owned by the user, on a trial.
     *
     * @param  array{hotel_name: string, country_code: string, city: string, phone: string, rooms: int}  $details
     */
    public function createHotel(User $user, array $details, string $source): Hotel
    {
        $template = CountryTemplate::query()->where('country_code', $details['country_code'])->firstOrFail();

        $hotel = $this->hotels->create([
            'name' => $details['hotel_name'],
            'country_code' => $template->country_code,
            'timezone' => $template->timezone,
            'currency' => $template->currency,
            'default_locale' => $template->locale,
            'owner_email' => $user->email,
            'status' => HotelStatus::Trial->value,
        ]);
        $hotel->forceFill(['signup_source' => $source])->save();

        app(TenantContext::class)->run($hotel, function () use ($template, $details): void {
            HotelSettings::current()->update([
                'city' => $details['city'],
                'phone_e164' => $details['phone'],
                'check_in_time' => $template->check_in,
                'check_out_time' => $template->check_out,
                'enabled_locales' => array_values(array_unique([$template->locale, 'fr', 'en'])),
            ]);

            foreach ($template->tax_rules as $i => $rule) {
                TaxRate::query()->create([
                    'name' => ['fr' => $rule['name'], 'en' => $rule['name']],
                    'type' => 'percentage',
                    'percentage_bps' => (int) round(((float) $rule['rate']) * 100),
                    'inclusive' => (bool) $rule['inclusive'],
                    'applies_to' => $rule['applies_to'],
                    'active' => true,
                    'sort_order' => $i,
                ]);
            }
        });

        return $hotel;
    }

    /**
     * Create the hotel once the owner's email is confirmed (once).
     */
    public function complete(User $user): ?Hotel
    {
        $pending = PendingSignup::query()->where('user_id', $user->getKey())->whereNull('completed_at')->first();

        if ($pending === null) {
            return null;
        }

        $hotel = DB::transaction(function () use ($pending, $user): Hotel {
            $hotel = $this->createHotel($user, $pending->details, $pending->signup_code_id !== null ? 'code' : 'self');
            LegalAcceptance::query()->where('user_id', $user->getKey())->whereNull('hotel_id')->update(['hotel_id' => $hotel->id]);
            $pending->forceFill(['hotel_id' => $hotel->id, 'completed_at' => now()])->save();

            return $hotel;
        });
        $template = CountryTemplate::query()->where('country_code', $pending->details['country_code'])->firstOrFail();

        Audit::record('signup.hotel_created', 'Hotel created from a self sign-up', subject: $hotel, causer: $user, properties: ['country' => $template->country_code]);

        $settings = SignupSettings::current();
        foreach ($settings->notify_emails ?? [] as $to) {
            Mail::to($to)->queue(new NewSignupMail($hotel->id));
        }

        if ($settings->emails_enabled) {
            Mail::to($user->email)->queue(new \App\Domains\Onboarding\Mail\OnboardingMail($hotel->id, 'welcome'));
        }

        return $hotel;
    }
}
