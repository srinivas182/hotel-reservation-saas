<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\Billing\CreditKind;
use App\Domains\Billing\Credits;
use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Subscriptions;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

it('passes guests to staff when AI credits are used up, and resumes with a pack', function (): void {
    ['hotel' => $hotel] = assistantHotel();
    subscribe($hotel, billingPlan('mini', [], null, 2, 10));
    $ask = fn () => app(TenantContext::class)->run($hotel, fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Bonjour')]));

    HotelAssistantAgent::fake(array_fill(0, 5, aiReply('Bonjour, que puis-je faire pour vous ?')));
    // Two credits, then the small buffer lets one more reply finish.
    $ask();
    $ask();
    $ask();
    expect($ask()->handoverReason)->toBe('ai_unavailable:no_credits');

    $pack = CreditPack::query()->create(['code' => 'ai-100', 'name' => ['fr' => '100'], 'kind' => 'ai', 'credits' => 100, 'prices' => ['XOF' => 1000]]);
    app(TenantContext::class)->run($hotel, fn () => app(Subscriptions::class)->addPack($pack, User::factory()->create()));
    $resumed = $ask();
    expect($resumed->handoverReason)->toBeNull()->and($resumed->handover)->toBeFalse();
});

it('holds guest emails when email credits run out but always sends security emails', function (): void {
    $hotel = Hotel::factory()->create(['currency' => 'XOF']);
    subscribe($hotel, billingPlan('mini', [], null, 10, 1));
    app(TenantContext::class)->run($hotel, fn () => app(Credits::class)->consume(CreditKind::Email, 2 * Credits::UNIT));

    $allowance = app(App\Domains\Messaging\Email\EmailAllowance::class);
    expect($allowance->allows($hotel, EmailCategory::Guest))->toBeFalse()
        ->and($allowance->allows($hotel, EmailCategory::Security))->toBeTrue()
        ->and($allowance->allows($hotel, EmailCategory::System))->toBeTrue();
});

it('hides modules the plan does not include, from screens and from the assistant', function (): void {
    ['hotel' => $hotel] = assistantHotel();
    $owner = staffMember($hotel, SystemRole::Owner);

    asStaffOf($hotel, $owner)->get(route('hotel.restaurant'))->assertOk();

    subscribe($hotel, billingPlan('rooms-only'));
    asStaffOf($hotel, $owner)->get(route('hotel.restaurant'))->assertForbidden();
    asStaffOf($hotel, $owner)->get(route('hotel.events'))->assertForbidden();

    $tools = app(TenantContext::class)->run($hotel, fn () => collect((new HotelAssistantAgent('x', [], new App\Domains\Assistant\Engine\ToolContext('fr')))->tools())->map(fn ($t) => $t->name())->all());
    expect($tools)->not->toContain('book_table')->not->toContain('place_order')->not->toContain('event_enquiry')->toContain('get_quote');
});

it('lets the platform manage plans, subscriptions and credits, and owners see their billing', function (): void {
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create();
    $hotel = Hotel::factory()->create(['currency' => 'XOF']);

    $this->actingAs($admin)->post(route('platform.billing.plans.store'), [
        'code' => 'pro', 'name' => ['fr' => 'Pro', 'en' => 'Pro'], 'modules' => ['restaurant'], 'staff_limit' => 10,
        'ai_credits' => 3000, 'email_credits' => 2000, 'prices' => ['XOF' => ['monthly' => 45000, 'yearly' => 450000]], 'trial_days' => 14, 'is_default' => true, 'active' => true,
    ])->assertSessionHasNoErrors();
    $plan = Plan::query()->sole();

    $this->actingAs($admin)->post(route('platform.hotels.billing.subscribe', $hotel), ['plan_id' => $plan->id, 'interval' => 'monthly', 'currency' => 'XOF', 'trial' => true])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('platform.hotels.billing.adjust', $hotel), ['kind' => 'ai', 'credits' => 500, 'reason' => 'Lancement du pilote'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('platform.hotels.billing.adjust', $hotel), ['kind' => 'ai', 'credits' => 500])->assertSessionHasErrors('reason');

    expect(App\Domains\Audit\Models\AuditLog::query()->where('event', 'billing.credits_adjusted')->sole()->properties['reason'])->toBe('Lancement du pilote');

    $owner = staffMember($hotel, SystemRole::Owner);
    asStaffOf($hotel, $owner)->get(route('hotel.billing'))->assertOk()->assertInertia(fn ($page) => $page->where('subscription.plan_id', $plan->id)->where('subscription.status', 'trialing'));
    asStaffOf($hotel, staffMember($hotel, SystemRole::Reception))->get(route('hotel.billing'))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('platform.billing.index'))->assertForbidden();
});
