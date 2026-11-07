<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Onboarding\Models\CountryTemplate;
use App\Domains\Onboarding\Models\SignupCode;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
});

it('ships country templates for West Africa and the rest of Africa', function (): void {
    expect(CountryTemplate::query()->count())->toBeGreaterThanOrEqual(30)
        ->and(CountryTemplate::query()->where('country_code', 'CI')->sole()->tax_rules[0])->toMatchArray(['name' => 'TVA', 'rate' => 18])
        ->and(CountryTemplate::query()->where('country_code', 'SN')->value('currency'))->toBe('XOF')
        ->and(CountryTemplate::query()->where('country_code', 'CM')->value('currency'))->toBe('XAF')
        ->and(CountryTemplate::query()->where('country_code', 'NG')->value('locale'))->toBe('en');
});

it('lets the platform set sign-up rules, codes and country templates', function (): void {
    $this->actingAs($this->admin)->put(route('platform.signups.settings'), [
        'mode' => 'open', 'approval_required' => true, 'block_disposable_emails' => false, 'emails_enabled' => true, 'notify_emails' => ['golive@cnh.example'],
    ])->assertSessionHasNoErrors();
    expect(SignupSettings::current()->mode)->toBe('open')->and(SignupSettings::current()->notify_emails)->toBe(['golive@cnh.example']);

    $this->actingAs($this->admin)->post(route('platform.signups.codes.store'), ['note' => 'CNH Abidjan', 'max_uses' => 20])->assertSessionHasNoErrors();
    $code = SignupCode::query()->sole();
    expect(strlen($code->code))->toBe(8);
    $this->actingAs($this->admin)->post(route('platform.signups.codes.revoke', $code));
    expect($code->fresh()->usable())->toBeFalse();

    $this->actingAs($this->admin)->post(route('platform.country-templates.store'), [
        'country_code' => 'xk', 'name_fr' => 'Test', 'name_en' => 'Test', 'currency' => 'eur', 'timezone' => 'Europe/Paris', 'locale' => 'fr',
        'check_in' => '15:00', 'check_out' => '11:00', 'taxes' => [['name' => 'TVA', 'rate' => 5.5, 'inclusive' => false]], 'categories' => 'Simple, Double', 'active' => true,
    ])->assertSessionHasNoErrors();
    $tpl = CountryTemplate::query()->where('country_code', 'XK')->sole();
    expect($tpl->currency)->toBe('EUR')->and($tpl->category_suggestions)->toBe(['Simple', 'Double']);

    $this->actingAs($this->admin)->get(route('platform.signups.index'))->assertOk()->assertInertia(fn ($page) => $page->has('codes', 1)->has('legal', 3));
    $this->actingAs(User::factory()->create())->get(route('platform.signups.index'))->assertForbidden();
});

it('suspends a hotel: no access, no AI, data kept; and reactivates it', function (): void {
    ['hotel' => $hotel] = assistantHotel();
    $owner = staffMember($hotel, SystemRole::Owner);

    $this->actingAs($this->admin)->post(route('platform.hotels.suspend', $hotel), ['suspend' => true, 'reason' => 'Faux compte'])->assertSessionHasNoErrors();
    expect($hotel->fresh()->status)->toBe(HotelStatus::Suspended)
        ->and(AuditLog::query()->where('event', 'signup.suspended')->sole()->properties['reason'])->toBe('Faux compte');

    HotelAssistantAgent::fake([aiReply('Bonjour')]);
    $result = app(TenantContext::class)->run($hotel->fresh(), fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Bonjour')]));
    expect($result->handoverReason)->toBe('ai_unavailable:suspended');

    $this->actingAs($owner)->get(route('hotel.today'))->assertForbidden();

    $this->actingAs($this->admin)->post(route('platform.hotels.suspend', $hotel), ['suspend' => false]);
    expect($hotel->fresh()->status)->toBe(HotelStatus::Trial);
});
