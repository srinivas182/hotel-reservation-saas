<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Assistant\Knowledge\KnowledgeVersion;
use App\Domains\Onboarding\Mail\OnboardingMail;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Onboarding\PackDraftAgent;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    ['hotel' => $this->hotel] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->owner->forceFill(['two_factor_secret' => encrypt('s'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
    $this->hotel->forceFill(['owner_user_id' => $this->owner->id])->save();
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
    SignupSettings::current()->update(['notify_emails' => ['golive@cnh.example']]);
});

function draftReply(): string
{
    return json_encode(['entries' => [
        ['locale' => 'fr', 'section' => 'restaurant', 'question' => 'Le petit-déjeuner est-il inclus ?', 'content' => 'Petit-déjeuner de 6 h 30 à 10 h, inclus.'],
        ['locale' => 'en', 'section' => 'restaurant', 'question' => 'Is breakfast included?', 'content' => 'Breakfast 6:30 to 10 am, included.'],
        ['locale' => 'fr', 'section' => 'parking', 'question' => null, 'content' => 'Parking gratuit et gardé.'],
        ['locale' => 'xx', 'section' => 'parking', 'content' => 'ignored'],
        ['locale' => 'fr', 'section' => 'unknown', 'content' => 'ignored'],
    ]]);
}

it('drafts the information pack from the questionnaire without publishing it', function (): void {
    PackDraftAgent::fake([draftReply()]);
    $published = ($this->in)(fn () => KnowledgeVersion::query()->whereNotNull('published_at')->count());

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.pack-draft'), ['answers' => ['breakfast' => 'Inclus, 6h30-10h', 'parking' => 'Gratuit, gardé', 'pets' => '']])
        ->assertRedirect(route('hotel.assistant.knowledge'));

    $entries = ($this->in)(fn () => KnowledgeEntry::query()->orderBy('sort_order')->get());
    $in = fn (array $sections) => $entries->filter(fn (KnowledgeEntry $e): bool => in_array($e->section->value, $sections, true))->count();
    expect($in(['restaurant', 'parking']))->toBe(3)
        ->and($in(['payments']))->toBe(2) // other sections untouched
        ->and(($this->in)(fn () => KnowledgeVersion::query()->whereNotNull('published_at')->count()))->toBe($published)
        ->and(($this->in)(fn () => OnboardingProgress::current()->questionnaire))->toEqual(['breakfast' => 'Inclus, 6h30-10h', 'parking' => 'Gratuit, gardé']);

    PackDraftAgent::fake(['not json']);
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.pack-draft'), ['answers' => ['parking' => 'Oui']])->assertSessionHasErrors('answers');
});

it('lists what blocks going live, and lets the owner ask once it is ready', function (): void {
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.setup'))->assertInertia(fn ($page) => $page->where('golive.blockers', fn ($b) => count($b) > 0));
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.golive'))->assertSessionHasErrors('golive');

    ($this->in)(fn () => HotelAssistantSettings::current()->forceFill(['readiness_override_at' => now(), 'readiness_override_reason' => 'Test'])->save());
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.golive'))->assertSessionHasNoErrors();

    expect(($this->in)(fn () => OnboardingProgress::current()->golive_requested_at))->not->toBeNull()
        ->and(($this->in)(fn () => HotelAssistantSettings::current()->mode))->toBe(AssistantMode::Off);
    Mail::assertQueued(OnboardingMail::class, fn (OnboardingMail $m): bool => $m->kind === 'golive_request' && $m->hasTo('golive@cnh.example'));
});

it('needs two-step login before asking to go live', function (): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->forceFill(['readiness_override_at' => now()])->save());
    $this->owner->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

    expect(($this->in)(fn () => app(App\Domains\Onboarding\GoLive::class)->blockers($this->owner->fresh())))->toBe([__('Two-step login for your account')]);
});

it('lets the platform approve (supervised mode) or refuse with a reason', function (bool $approve): void {
    ($this->in)(fn () => HotelAssistantSettings::current()->forceFill(['readiness_override_at' => now()])->save());
    ($this->in)(fn () => app(App\Domains\Onboarding\GoLive::class)->request($this->owner));

    $this->actingAs($this->admin)->post(route('platform.hotels.golive', $this->hotel), ['approve' => false])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->post(route('platform.hotels.golive', $this->hotel), ['approve' => $approve, 'reason' => $approve ? null : 'Ajoutez vos photos et le lien d’avis.'])->assertSessionHasNoErrors();

    expect(($this->in)(fn () => HotelAssistantSettings::current()->mode))->toBe($approve ? AssistantMode::Supervised : AssistantMode::Off)
        ->and(($this->in)(fn () => OnboardingProgress::current()->golive_decision))->toBe($approve ? 'approved' : 'refused');
    Mail::assertQueued(OnboardingMail::class, fn (OnboardingMail $m): bool => $m->kind === ($approve ? 'golive_approved' : 'golive_refused') && $m->hasTo($this->owner->email));
})->with([
    'approved' => [true],
    'refused' => [false],
]);

it('starts supervised mode at once when approval is not required', function (): void {
    SignupSettings::current()->update(['approval_required' => false]);
    ($this->in)(fn () => HotelAssistantSettings::current()->forceFill(['readiness_override_at' => now()])->save());

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.golive'))->assertSessionHasNoErrors();

    expect(($this->in)(fn () => HotelAssistantSettings::current()->mode))->toBe(AssistantMode::Supervised);
});
