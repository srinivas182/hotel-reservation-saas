<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Subscription;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Onboarding\Mail\NewSignupMail;
use App\Domains\Onboarding\Models\LegalAcceptance;
use App\Domains\Onboarding\Models\PendingSignup;
use App\Domains\Onboarding\Models\SignupCode;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Billing/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    publishLegalDefaults();
});

it('follows the sign-up mode: closed, invitation code, open', function (): void {
    $this->withoutMiddleware(App\Http\Middleware\ThrottlePerRoute::class);
    signupMode('closed');
    $this->get(route('signup'))->assertOk()->assertInertia(fn ($page) => $page->where('mode', 'closed'));
    $this->post(route('signup.store'), signupPayload())->assertForbidden();

    signupMode('code');
    $this->post(route('signup.store'), signupPayload())->assertSessionHasErrors('code');
    SignupCode::query()->create(['code' => 'OLD', 'expires_at' => now()->subDay()]);
    SignupCode::query()->create(['code' => 'FULL', 'max_uses' => 1, 'uses' => 1]);
    $this->post(route('signup.store'), signupPayload(['code' => 'old']))->assertSessionHasErrors('code');
    $this->post(route('signup.store'), signupPayload(['code' => 'FULL']))->assertSessionHasErrors('code');

    SignupCode::query()->create(['code' => 'CNH-ABJ', 'max_uses' => 5]);
    $this->post(route('signup.store'), signupPayload(['code' => 'cnh-abj']))->assertRedirect(route('verification.notice'));
    expect(SignupCode::query()->where('code', 'CNH-ABJ')->value('uses'))->toBe(1);

    auth()->logout();
    signupMode('open');
    $this->post(route('signup.store'), signupPayload(['email' => 'open@baobab.example']))->assertRedirect(route('verification.notice'));
    expect(User::query()->count())->toBe(2);
});

it('stops spam: hidden field, throwaway addresses and too many attempts', function (): void {
    signupMode('open');

    $this->post(route('signup.store'), signupPayload(['website' => 'http://spam.example']))->assertRedirect(route('signup'));
    $this->post(route('signup.store'), signupPayload(['email' => 'x@mailinator.com']))->assertSessionHasErrors('email');
    expect(User::query()->count())->toBe(0);

    foreach (range(1, 3) as $i) {
        $this->post(route('signup.store'), signupPayload(['email' => "bad{$i}", 'password_confirmation' => 'x']));
    }
    $this->post(route('signup.store'), signupPayload(['email' => 'late@baobab.example']))->assertStatus(429);
});

it('creates the hotel from the country template only once the email is confirmed', function (): void {
    signupMode('code');
    SignupCode::query()->create(['code' => 'CNH-ABJ']);
    SignupSettings::current()->update(['notify_emails' => ['ops@cnh.example']]);
    $plan = billingPlan('pro', [], null, 300, 200, 45000);
    $plan->update(['is_default' => true]);

    $this->post(route('signup.store'), signupPayload(['code' => 'CNH-ABJ']));
    $user = User::query()->sole();
    expect(Hotel::query()->count())->toBe(0)
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and(LegalAcceptance::query()->where('user_id', $user->id)->count())->toBe(3);

    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->actingAs($user)->get($url);

    $hotel = Hotel::query()->sole();
    expect($hotel->name)->toBe('Hôtel Le Baobab')
        ->and($hotel->currency)->toBe('XOF')
        ->and($hotel->timezone)->toBe('Africa/Abidjan')
        ->and($hotel->default_locale)->toBe('fr')
        ->and($hotel->signup_source)->toBe('code')
        ->and($hotel->owner_user_id)->toBe($user->id)
        ->and(LegalAcceptance::query()->where('hotel_id', $hotel->id)->count())->toBe(3);

    app(TenantContext::class)->run($hotel, function (): void {
        $tax = TaxRate::query()->sole();
        expect($tax->percentage_bps)->toBe(1800)->and($tax->inclusive)->toBeTrue()
            ->and(HotelSettings::current()->city)->toBe('Bouaké')
            ->and(HotelSettings::current()->check_in_time)->toStartWith('14:00')
            ->and(Subscription::query()->sole()->status)->toBe('trialing');
    });
    Mail::assertQueued(NewSignupMail::class, fn (NewSignupMail $m): bool => $m->hasTo('ops@cnh.example'));

    // Confirming twice creates nothing more.
    event(new Verified($user));
    expect(Hotel::query()->count())->toBe(1)->and(PendingSignup::query()->sole()->completed_at)->not->toBeNull();
});

it('asks owners to accept a new version of a legal text at their next request', function (): void {
    signupMode('open');
    $this->post(route('signup.store'), signupPayload());
    $user = User::query()->sole();
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt(json_encode(['r'])), 'two_factor_confirmed_at' => now()])->save();
    $user->markEmailAsVerified();
    event(new Verified($user));

    $before = $this->actingAs($user)->get(route('dashboard'));
    expect($before->headers->get('Location'))->not->toBe(route('legal.accept'));

    $this->flushSession();
    $this->actingAs(User::factory()->platformAdmin()->withTwoFactor()->create())
        ->post(route('platform.legal.publish', 'terms'), ['fr' => '# CGU v2', 'en' => '# Terms v2'])->assertSessionHasNoErrors();
    expect(App\Domains\Onboarding\Models\LegalDocument::current('terms')?->version)->toBe(2);
    $this->flushSession();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('legal.accept'));
    $this->actingAs($user)->get(route('legal.accept'))->assertInertia(fn ($page) => $page->has('documents', 1)->where('documents.0.version', 2));
    $this->actingAs($user)->post(route('legal.accept.store'), ['accept' => true])->assertRedirect();
    expect($this->actingAs($user)->get(route('dashboard'))->headers->get('Location'))->not->toBe(route('legal.accept'));

    $this->get(route('legal.show', 'terms'))->assertOk()->assertSee('v2');
});
