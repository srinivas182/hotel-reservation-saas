<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Push\Models\PushSubscription;
use App\Domains\Push\PushLinks;
use App\Domains\Push\PushSender;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        private readonly PushSender $push,
    ) {}

    /**
     * @param  bool  $urgent  goes through quiet hours (a request about to expire)
     */
    public function notify(AlertKind $kind, string $details, ?string $subjectId = null, ?StaffCard $card = null, bool $urgent = false): int
    {
        $sent = 0;

        // A phone for WhatsApp, or the app on a phone (Sprint D).
        $settings = StaffAlertSettings::query()->with('user')
            ->where(fn ($q) => $q->whereNotNull('alert_phone')->orWhereIn('user_id', PushSubscription::query()->select('user_id')))
            ->get();

        foreach ($settings as $setting) {
            $user = $setting->user;

            if ($user === null || ! $setting->wants($kind) || ! $this->isActiveMember($user) || ! $user->can($kind->permission()->value)) {
                continue;
            }

            if ($this->sendTo($setting, $user, $kind, $details, $subjectId, $card, repeat: $urgent, urgent: $urgent)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  bool  $repeat  send even if this person was alerted about the subject in the last 10 minutes (escalation)
     */
    public function sendTo(StaffAlertSettings $setting, User $user, AlertKind $kind, string $details, ?string $subjectId = null, ?StaffCard $card = null, bool $repeat = false, bool $urgent = false, bool $whatsappOnly = false): bool
    {
        $hotel = $this->context->hotel();

        if (! $repeat && $subjectId !== null && StaffAlert::query()
            ->where('user_id', $user->getKey())
            ->where('subject_id', $subjectId)
            ->where('created_at', '>', now()->subMinutes(self::REPEAT_MINUTES))
            ->exists()) {
            return false;
        }

        // Quiet hours (Sprint D): nothing, except what cannot wait.
        if (! $urgent && $kind !== AlertKind::Test && $setting->inQuietHours(CarbonImmutable::now($hotel->timezone))) {
            StaffAlert::query()->create(['user_id' => $user->getKey(), 'kind' => $kind->value, 'subject_id' => $subjectId, 'how' => 'skipped', 'reason' => 'quiet_hours']);

            return false;
        }

        $locale = $user->preferred_locale ?: $hotel->default_locale;

        // The app first: free, and on the lock screen. WhatsApp follows if it is not opened.
        if (! $whatsappOnly && $setting->push_enabled && $this->push->hasDevices($user) && $this->pushTo($setting, $user, $kind, $details, $subjectId, $card, $locale, $urgent)) {
            return true;
        }

        if ($setting->alert_phone === null) {
            return false;
        }

        if (! $this->underDailyCap()) {
            StaffAlert::query()->create(['user_id' => $user->getKey(), 'kind' => $kind->value, 'subject_id' => $subjectId, 'how' => 'skipped', 'reason' => 'daily_cap']);

            return false;
        }

        $title = __($kind->label(), locale: $locale);
        $text = __('Alert for :hotel: :title. :details. Open the dashboard to respond.', ['hotel' => $hotel->name, 'title' => $title, 'details' => Str::limit($details, 300)], $locale);

        $usageId = (string) Str::ulid();
        $buttons = $card?->for($user, $setting, $locale);
        $result = $buttons === null ? null : $this->whatsapp->sendButtons((string) $setting->alert_phone, $buttons['text'], $buttons['buttons'], $buttons['template'], $buttons['values'], $locale, $hotel->default_locale, $usageId);

        // No approved button template outside the window: the plain alert still goes.
        if ($result === null || ($result['how'] === 'skipped' && $result['reason'] === 'template_not_approved')) {
            $result = $this->whatsapp->send(
                (string) $setting->alert_phone,
                $text,
                'staff_alert',
                [$hotel->name, $title, Str::limit($details, 300)],
                $locale,
                $hotel->default_locale,
                $usageId,
            );
        }

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

    /**
     * The alert as an app notification, with Accept on the notification for
     * a request card. A WhatsApp backup is scheduled in case it is not opened.
     */
    private function pushTo(StaffAlertSettings $setting, User $user, AlertKind $kind, string $details, ?string $subjectId, ?StaffCard $card, string $locale, bool $urgent): bool
    {
        $hotel = $this->context->hotel();
        $alert = StaffAlert::query()->create(['user_id' => $user->getKey(), 'kind' => $kind->value, 'subject_id' => $subjectId, 'how' => 'push']);
        $built = $card?->for($user, $setting, $locale);
        $url = PushLinks::for($kind, $subjectId);
        $actions = [];

        if ($built !== null) {
            // Accept works from the notification; Decline needs a reason, so it opens the request.
            $actions[] = ['action' => 'act', 'title' => $built['buttons'][0]['title'], 'token' => $built['buttons'][0]['id']];
            $actions[] = ['action' => 'open', 'title' => $built['buttons'][1]['title'] ?? (string) __('Open', locale: $locale)];
        }

        $delivered = $this->push->toUser($user, [
            'title' => (string) __($kind->label(), locale: $locale).' · '.$hotel->name,
            'body' => $built !== null ? implode("\n", array_slice(explode("\n", $built['text']), 1)) : Str::limit($details, 300),
            'url' => $url,
            'tag' => $kind->value.':'.($subjectId ?? $alert->id),
            'actions' => $actions,
            'alert' => $alert->id,
            'opened' => PushLinks::openedSignature($hotel->id, $alert->id),
            'urgent' => $urgent || $kind === AlertKind::NewRequest,
        ]);

        if ($delivered === 0) {
            $alert->forceFill(['how' => 'skipped', 'reason' => 'push_failed'])->save();

            return false;
        }

        if ($setting->alert_phone !== null && $kind !== AlertKind::Test) {
            SendWhatsAppIfUnopened::dispatch($alert->id, $kind->value, $details, $urgent)->delay(now()->addMinutes((int) config('webpush.whatsapp_backup_minutes')));
        }

        return true;
    }

    /**
     * Whether the hotel may still send staff messages today (Meta charges
     * the hotel for every template).
     */
    public function underDailyCap(): bool
    {
        return StaffAlert::query()->whereIn('how', ['text', 'template'])->where('created_at', '>=', now()->subDay())->count() < HotelAssistantSettings::current()->daily_alert_cap;
    }

    private function isActiveMember(User $user): bool
    {
        return $this->context->hotel()->members()->wherePivot('status', 'active')->whereKey($user->getKey())->exists();
    }
}
