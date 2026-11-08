<?php

declare(strict_types=1);

use App\Domains\Onboarding\Mail\OnboardingMail;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Billing/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    $this->travelTo('2026-11-01 07:00:00');
    $this->owner = User::factory()->create(['email' => 'owner@baobab.example']);
    $this->hotel = Hotel::factory()->create(['timezone' => 'Africa/Abidjan', 'owner_user_id' => $this->owner->id, 'signup_source' => 'self']);
    subscribe($this->hotel, billingPlan('pro'), trial: true); // 14 days: 1–15 November
    $this->kinds = fn (): array => Mail::queued(OnboardingMail::class)->map(fn (OnboardingMail $m): string => $m->kind)->values()->all();
});

it('reminds owners stuck in setup on days 2, 5 and 9, once each, then warns before the trial ends', function (): void {
    foreach (['2026-11-01', '2026-11-02', '2026-11-02', '2026-11-05', '2026-11-09', '2026-11-10', '2026-11-12', '2026-11-12', '2026-11-15'] as $day) {
        $this->travelTo($day.' 08:00:00');
        $this->artisan('onboarding:nudges');
    }

    expect(($this->kinds)())->toBe(['setup', 'setup', 'setup', 'trial_3', 'trial_last']);
});

it('sends no setup reminders to hotels created by the platform, and nothing when emails are off', function (): void {
    $this->hotel->forceFill(['signup_source' => 'platform'])->save();
    $this->travelTo('2026-11-05 08:00:00');
    $this->artisan('onboarding:nudges');
    expect(($this->kinds)())->toBe([]);

    SignupSettings::current()->update(['emails_enabled' => false]);
    $this->travelTo('2026-11-13 08:00:00');
    $this->artisan('onboarding:nudges');
    expect(($this->kinds)())->toBe([]);
});

it('does not send a burst of old reminders to a hotel that starts late', function (): void {
    $this->travelTo('2026-11-10 08:00:00');
    $this->artisan('onboarding:nudges');
    $this->artisan('onboarding:nudges');

    expect(($this->kinds)())->toBe(['setup']);
});
