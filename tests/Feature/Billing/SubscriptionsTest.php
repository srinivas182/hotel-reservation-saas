<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Billing\CreditKind;
use App\Domains\Billing\Credits;
use App\Domains\Billing\Exceptions\PlanChangeNotPossible;
use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionChange;
use App\Domains\Billing\Subscriptions;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-01 08:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->essentiel = billingPlan('essentiel', [], 3, 100, 50, 25000);
    $this->pro = billingPlan('pro', ['restaurant', 'ordering'], 10, 300, 200, 45000);
    $this->left = fn (CreditKind $kind): int => ($this->in)(fn () => app(Credits::class)->summary($kind)['left']);
});

it('starts a trial with trial credits and turns it into a paid period with the month\'s credits', function (): void {
    $subscription = subscribe($this->hotel, $this->pro, trial: true);

    expect($subscription->status)->toBe('trialing')
        ->and($subscription->period_end->toDateString())->toBe('2026-11-15')
        ->and(($this->left)(CreditKind::Ai))->toBe(30000);

    $this->travelTo('2026-11-15 09:00:00');
    $this->artisan('billing:renew')->assertSuccessful();

    $subscription = ($this->in)(fn () => Subscription::query()->sole());
    expect($subscription->status)->toBe('active')
        ->and($subscription->period_end->toDateString())->toBe('2026-12-15')
        ->and(($this->left)(CreditKind::Ai))->toBe(30000) // trial credits expired, the month's granted
        ->and(($this->in)(fn () => SubscriptionChange::query()->where('kind', 'trial_converted')->sole()->amount_minor))->toBe(45000);
});

it('uses the month\'s credits before packs, and packs last after the month ends', function (): void {
    subscribe($this->hotel, $this->essentiel);
    $pack = CreditPack::query()->create(['code' => 'ai-500', 'name' => ['fr' => '500'], 'kind' => 'ai', 'credits' => 500, 'prices' => ['XOF' => 5000], 'valid_months' => 12]);
    ($this->in)(fn () => app(Subscriptions::class)->addPack($pack, User::factory()->create()));

    ($this->in)(fn () => app(Credits::class)->consume(CreditKind::Ai, 150 * Credits::UNIT));
    expect(($this->left)(CreditKind::Ai))->toBe(450 * Credits::UNIT);

    $this->travelTo('2026-12-01 09:00:00');
    $this->artisan('billing:renew');
    // New month: 100 granted again; the pack still has 450 left.
    expect(($this->left)(CreditKind::Ai))->toBe(550 * Credits::UNIT);
});

it('warns the owner once at 80 % and once at 100 %, and again after new credits', function (): void {
    subscribe($this->hotel, $this->essentiel);
    $consume = fn (int $credits) => ($this->in)(fn () => app(Credits::class)->consume(CreditKind::Ai, $credits * Credits::UNIT));

    $consume(79);
    $consume(1);
    $consume(5);
    $consume(15);
    $consume(1);

    expect(AuditLog::query()->where('event', 'billing.credits_warning')->pluck('properties')->map(fn ($p) => $p['level'])->all())->toBe([80, 100]);

    ($this->in)(fn () => app(Credits::class)->adjust(CreditKind::Ai, 100, 'Geste commercial', User::factory()->create()));
    $consume(90);
    expect(AuditLog::query()->where('event', 'billing.credits_warning')->count())->toBe(3);
});

it('allows a small buffer, then stops', function (): void {
    subscribe($this->hotel, $this->essentiel);
    $credits = fn () => app(Credits::class);

    ($this->in)(fn () => $credits()->consume(CreditKind::Ai, 104 * Credits::UNIT));
    expect(($this->in)(fn () => $credits()->canUse(CreditKind::Ai)))->toBeTrue();

    ($this->in)(fn () => $credits()->consume(CreditKind::Ai, 1 * Credits::UNIT));
    expect(($this->in)(fn () => $credits()->canUse(CreditKind::Ai)))->toBeFalse();
});

it('upgrades at once pro rata, and downgrades at the end of the period only when the hotel fits', function (): void {
    $subscription = subscribe($this->hotel, $this->essentiel);
    $this->travelTo('2026-11-16 08:00:00'); // half of a 30-day period left

    $owner = User::factory()->create();
    ($this->in)(fn () => app(Subscriptions::class)->changePlan($subscription, $this->pro, $owner));
    $change = ($this->in)(fn () => SubscriptionChange::query()->where('kind', 'upgraded')->sole());
    expect($change->amount_minor)->toBe(10000) // (45 000 − 25 000) × 15/30
        ->and(($this->left)(CreditKind::Ai))->toBe(200 * Credits::UNIT); // 100 + (300 − 100) × ½

    ($this->in)(fn () => Outlet::query()->create(['name' => ['fr' => 'Bar'], 'type' => 'bar', 'seats' => 10, 'public_slug' => 'bar']));
    expect(fn () => ($this->in)(fn () => app(Subscriptions::class)->changePlan($subscription->fresh(), $this->essentiel, $owner)))
        ->toThrow(PlanChangeNotPossible::class);

    ($this->in)(fn () => Outlet::query()->update(['active' => false]));
    ($this->in)(fn () => app(Subscriptions::class)->changePlan($subscription->fresh(), $this->essentiel, $owner));
    expect(($this->in)(fn () => $subscription->fresh()->plan_id))->toBe($this->pro->id);

    $this->travelTo('2026-12-01 09:00:00');
    $this->artisan('billing:renew');
    $subscription = ($this->in)(fn () => $subscription->fresh());
    expect($subscription->plan_id)->toBe($this->essentiel->id)->and($subscription->price_minor)->toBe(25000);
});

it('limits staff accounts to the plan', function (): void {
    subscribe($this->hotel, $this->essentiel);
    $owner = staffMember($this->hotel, SystemRole::Owner);
    staffMember($this->hotel, SystemRole::Reception);
    staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $owner)->post(route('staff.invitations.store'), ['email' => 'quatrieme@example.com', 'role_id' => systemRole($this->hotel, SystemRole::Reception)->id])
        ->assertSessionHasErrors('email');
});

it('leaves hotels without a subscription unlimited', function (): void {
    ($this->in)(fn () => app(Credits::class)->consume(CreditKind::Ai, 1_000_000));

    expect(($this->in)(fn () => app(Credits::class)->canUse(CreditKind::Ai)))->toBeTrue();
});
