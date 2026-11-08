<?php

declare(strict_types=1);

use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Onboarding\Mail\OnboardingMail;
use App\Domains\Onboarding\Models\SignupCode;
use App\Domains\Onboarding\PackDraftAgent;
use App\Domains\Rooms\Models\Room;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Billing/helpers.php';

it('takes a hotel from sign-up to going live', function (): void {
    Mail::fake();
    publishLegalDefaults();
    setUpAiModels();
    billingPlan('pro', [], null, 300, 200, 45000)->update(['is_default' => true]);
    signupMode('code');
    SignupCode::query()->create(['code' => 'BOUAKE']);

    // 1. Sign-up, then email confirmation creates the hotel (French, FCFA, TVA, trial).
    $this->post(route('signup.store'), signupPayload(['code' => 'BOUAKE']))->assertRedirect(route('verification.notice'));
    $owner = User::query()->sole();
    $owner->forceFill(['two_factor_secret' => encrypt('s'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($owner)->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $owner->id, 'hash' => sha1($owner->email)]));
    $hotel = Hotel::query()->sole();
    Mail::assertQueued(OnboardingMail::class, fn (OnboardingMail $m): bool => $m->kind === 'welcome');
    $in = fn (callable $callback) => app(TenantContext::class)->run($hotel, $callback);

    // 2. Rooms in one go.
    $this->actingAs($owner)->withSession(['current_hotel_id' => $hotel->id])
        ->post(route('hotel.setup.rooms'), ['categories' => [['name' => 'Standard', 'count' => 12, 'adults' => 2], ['name' => 'Suite', 'count' => 6, 'adults' => 3]], 'first_number' => 101]);
    expect($in(fn () => Room::query()->count()))->toBe(18);

    // 3. Information pack drafted from the questionnaire, reviewed and published by the owner.
    PackDraftAgent::fake([json_encode(['entries' => [['locale' => 'fr', 'section' => 'about', 'question' => null, 'content' => 'Hôtel calme au centre de Bouaké.'], ['locale' => 'en', 'section' => 'about', 'question' => null, 'content' => 'Quiet hotel in central Bouaké.']]])]);
    $this->actingAs($owner)->post(route('hotel.setup.pack-draft'), ['answers' => ['about' => 'Calme, centre-ville']])->assertRedirect();
    $in(fn () => app(KnowledgePack::class)->publish($owner));

    // 4. The platform lets this hotel past the infrastructure checks (as on staging), the owner asks, CNH approves.
    $in(fn () => HotelAssistantSettings::current()->forceFill(['readiness_override_at' => now()])->save());
    $this->actingAs($owner)->post(route('hotel.setup.golive'))->assertSessionHasNoErrors();
    $this->flushSession();
    $this->actingAs(User::factory()->platformAdmin()->withTwoFactor()->create())->post(route('platform.hotels.golive', $hotel), ['approve' => true])->assertSessionHasNoErrors();

    expect($in(fn () => HotelAssistantSettings::current()->mode))->toBe(AssistantMode::Supervised)
        ->and($hotel->fresh()->signup_source)->toBe('code');
    $this->actingAs(User::factory()->platformAdmin()->withTwoFactor()->create())->get(route('platform.signups.index'))
        ->assertInertia(fn ($page) => $page->where('funnel.0.count', 1)->where('funnel.6.key', 'live')->where('funnel.6.count', 1));
});
