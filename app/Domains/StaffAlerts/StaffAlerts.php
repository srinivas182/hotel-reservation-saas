<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * WhatsApp alerts to staff phones. Each person chooses which alerts they
 * want; one alert per person and subject every 10 minutes, and a daily
 * maximum per hotel (Meta charges the hotel for every template).
 */
final class StaffAlerts
{
    public const REPEAT_MINUTES = 10;

    public function __construct(
        private readonly TenantContext $context,
        private readonly WhatsAppDirect $whatsapp,
    ) {}

    public function notify(AlertKind $kind, string $details, ?string $subjectId = null): int
    {
        $sent = 0;

        $settings = StaffAlertSettings::query()->with('user')->whereNotNull('alert_phone')->get();

        foreach ($settings as $setting) {
            $user = $setting->user;

            if ($user === null || ! $setting->wants($kind) || ! $this->isActiveMember($user) || ! $user->can($kind->permission()->value)) {
                continue;
            }

            if ($this->sendTo($setting, $user, $kind, $details, $subjectId)) {
                $sent++;
            }
        }

        return $sent;
    }

    public function sendTo(StaffAlertSettings $setting, User $user, AlertKind $kind, string $details, ?string $subjectId = null): bool
    {
        $hotel = $this->context->hotel();

        if ($subjectId !== null && StaffAlert::query()
            ->where('user_id', $user->getKey())
            ->where('subject_id', $subjectId)
            ->where('created_at', '>', now()->subMinutes(self::REPEAT_MINUTES))
            ->exists()) {
            return false;
        }

        $cap = HotelAssistantSettings::current()->daily_alert_cap;

        if (StaffAlert::query()->whereIn('how', ['text', 'template'])->where('created_at', '>=', now()->subDay())->count() >= $cap) {
            StaffAlert::query()->create(['user_id' => $user->getKey(), 'kind' => $kind->value, 'subject_id' => $subjectId, 'how' => 'skipped', 'reason' => 'daily_cap']);

            return false;
        }

        $locale = $user->preferred_locale ?: $hotel->default_locale;
        $title = __($kind->label(), locale: $locale);
        $text = __('Alert for :hotel: :title. :details. Open the dashboard to respond.', ['hotel' => $hotel->name, 'title' => $title, 'details' => Str::limit($details, 300)], $locale);

        $result = $this->whatsapp->send(
            (string) $setting->alert_phone,
            $text,
            'staff_alert',
            [$hotel->name, $title, Str::limit($details, 300)],
            $locale,
            $hotel->default_locale,
            (string) Str::ulid(),
        );

        StaffAlert::query()->create([
            'user_id' => $user->getKey(),
            'kind' => $kind->value,
            'subject_id' => $subjectId,
            'how' => $result['how'],
            'reason' => $result['reason'],
            'wamid' => $result['wamid'],
        ]);

        return in_array($result['how'], ['text', 'template'], true);
    }

    private function isActiveMember(User $user): bool
    {
        return $this->context->hotel()->members()->wherePivot('status', 'active')->whereKey($user->getKey())->exists();
    }
}
