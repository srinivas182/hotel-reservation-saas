<?php

declare(strict_types=1);

namespace App\Domains\Ai;

use App\Domains\Ai\Exceptions\AiUnavailable;
use App\Domains\Ai\Mail\AiBudgetAlertMail;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\AiRun;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Audit;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Off switches and the daily spending limit (current hotel).
 */
final class AiControls
{
    public const ALERT_RATIO = 0.8;

    public function __construct(private readonly TenantContext $context) {}

    public function assertAllowed(): void
    {
        if (AiPlatformSettings::current()->kill_switch) {
            throw new AiUnavailable('platform_off', __('AI is switched off for the platform.'));
        }

        $settings = HotelAssistantSettings::current();

        if ($settings->kill_switch) {
            throw new AiUnavailable('hotel_off', __('AI is switched off for this hotel.'));
        }

        if ($this->spentToday() >= $settings->daily_budget_micro_usd) {
            throw new AiUnavailable('budget', __('Today’s AI spending limit has been reached.'));
        }
    }

    /**
     * AI spending today in the hotel's time zone, in micro-US dollars.
     */
    public function spentToday(): int
    {
        $hotel = $this->context->hotel();
        $start = HotelClock::today($hotel)->shiftTimezone($hotel->timezone)->utc();

        return (int) AiRun::query()->where('created_at', '>=', $start)->sum('cost_micro_usd');
    }

    /**
     * Tell the people who manage the assistant once a day when spending
     * passes 80% of the limit.
     */
    public function alertIfNearLimit(): void
    {
        $hotel = $this->context->hotel();
        $settings = HotelAssistantSettings::current();
        $today = HotelClock::today($hotel);

        if ($settings->daily_budget_micro_usd <= 0
            || $settings->budget_alerted_on?->toDateString() === $today->toDateString()
            || $this->spentToday() < $settings->daily_budget_micro_usd * self::ALERT_RATIO) {
            return;
        }

        $settings->forceFill(['budget_alerted_on' => $today])->save();

        Audit::record('ai.budget_alert', 'AI spending passed 80% of the daily limit', subject: $hotel, properties: [
            'spent_micro_usd' => $this->spentToday(),
            'limit_micro_usd' => $settings->daily_budget_micro_usd,
        ]);

        $recipients = $hotel->members()->get()->filter(fn (User $user): bool => $user->can('assistant.manage'));

        foreach ($recipients as $user) {
            Mail::to($user->email)->locale($hotel->default_locale)->queue(new AiBudgetAlertMail($hotel->name, $this->spentToday(), $settings->daily_budget_micro_usd));
        }
    }
}
