<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Onboarding\Mail\OnboardingMail;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;

/**
 * Onboarding emails: reminders on trial days 2, 5 and 9 while setup is
 * unfinished (self sign-ups), and trial-ending notices 3 days before and on
 * the last day. Each is sent at most once.
 */
final class Nudges
{
    public const SETUP_DAYS = [2, 5, 9];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SetupChecklist $checklist,
    ) {}

    public function run(): int
    {
        if (! SignupSettings::current()->emails_enabled) {
            return 0;
        }

        $hotel = $this->context->hotel();
        $email = $hotel->owner?->email;
        $subscription = Subscription::query()->first();

        if ($email === null || $subscription === null || $subscription->status !== 'trialing') {
            return 0;
        }

        $progress = OnboardingProgress::current();
        $sent = $progress->nudges_sent ?? [];
        $today = HotelClock::today($hotel);
        $day = (int) $subscription->period_start->setTimezone($hotel->timezone)->startOfDay()->diffInDays($today) + 1;
        $daysLeft = (int) $today->diffInDays($subscription->period_end->setTimezone($hotel->timezone)->startOfDay(), false);
        $kind = null;

        if ($hotel->signup_source !== 'platform' && ! $this->checklist->summary()['complete']) {
            foreach (array_reverse(self::SETUP_DAYS) as $nudgeDay) {
                if ($day >= $nudgeDay && ! isset($sent["setup_{$nudgeDay}"])) {
                    $kind = "setup_{$nudgeDay}";

                    break;
                }
            }
        }

        if ($kind === null && $daysLeft <= 3 && $daysLeft > 0 && ! isset($sent['trial_3'])) {
            $kind = 'trial_3';
        } elseif ($kind === null && $daysLeft <= 0 && ! isset($sent['trial_last'])) {
            $kind = 'trial_last';
        }

        if ($kind === null) {
            return 0;
        }

        // Older setup reminders are marked too, so a late start never sends a burst.
        foreach (self::SETUP_DAYS as $nudgeDay) {
            if (str_starts_with($kind, 'setup_') && $nudgeDay <= (int) substr($kind, 6)) {
                $sent["setup_{$nudgeDay}"] ??= now()->toIso8601String();
            }
        }
        $sent[$kind] = now()->toIso8601String();
        $progress->forceFill(['nudges_sent' => $sent])->save();
        Mail::to($email)->queue(new OnboardingMail($hotel->id, str_starts_with($kind, 'setup_') ? 'setup' : $kind));

        return 1;
    }
}
