<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts\Morning;

use App\Domains\Audit\Audit;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestCard;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\StaffActions\StaffActionRouter;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlert;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\StaffAlerts\WhatsAppDirect;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Sends each person who asked for it their morning summary once, at their
 * time (Sprint C), with detail buttons, then the WhatsApp requests still
 * waiting as cards they can answer.
 */
final class MorningSummarySender
{
    /** Waiting requests sent as cards after the summary; the rest are counted. */
    public const MAX_CARDS = 3;

    public function __construct(
        private readonly TenantContext $context,
        private readonly MorningSummary $summary,
        private readonly WhatsAppDirect $whatsapp,
        private readonly StaffActionRouter $router,
        private readonly StaffAlerts $alerts,
    ) {}

    public function sendDue(): int
    {
        $hotel = $this->context->hotel();
        $today = HotelClock::today($hotel);
        $now = CarbonImmutable::now($hotel->timezone)->format('H:i');
        $sent = 0;
        $figures = null;

        $due = StaffAlertSettings::query()->with('user')
            ->whereNotNull('morning_time')->whereNotNull('alert_phone')
            ->where('morning_time', '<=', $now)
            ->where(fn ($q) => $q->whereNull('morning_sent_on')->orWhere('morning_sent_on', '<', $today->toDateString()))
            ->get();

        foreach ($due as $setting) {
            $user = $setting->user;
            $setting->forceFill(['morning_sent_on' => $today])->save();

            if ($user === null || ! $hotel->members()->wherePivot('status', 'active')->whereKey($user->getKey())->exists()) {
                continue;
            }

            $figures ??= $this->summary->build();

            if ($figures['quiet'] && ! $setting->morning_quiet_days) {
                continue;
            }

            if (! $this->alerts->underDailyCap()) {
                StaffAlert::query()->create(['user_id' => $user->getKey(), 'kind' => 'morning_summary', 'how' => 'skipped', 'reason' => 'daily_cap']);

                continue;
            }

            $locale = $user->preferred_locale ?: $hotel->default_locale;
            $money = MorningSummaryActions::canSeeMoney($user);
            $buttons = $this->router->buttons($today->toDateString(), $user, 24 * 3600,
                'mx_arrivals', (string) __('Arrivals', locale: $locale),
                'mx_inhouse', (string) __('In-house', locale: $locale),
                'mx_collect', (string) __('To collect', locale: $locale),
            );

            // Labels built without an explicit language (payment methods) follow the person's.
            $previous = app()->getLocale();
            app()->setLocale($locale);
            $message = MorningSummaryText::message($figures, (string) $user->first_name, $locale, $money);
            $line = MorningSummaryText::line($figures, $locale, $money);
            app()->setLocale($previous);

            $result = $this->whatsapp->sendButtons(
                (string) $setting->alert_phone,
                $message,
                $buttons,
                'owner_morning_summary',
                [$hotel->name, $today->settings(['locale' => $locale])->isoFormat('D MMMM'), $line],
                $locale,
                $hotel->default_locale,
                (string) Str::ulid(),
            );

            StaffAlert::query()->create(['user_id' => $user->getKey(), 'kind' => 'morning_summary', 'how' => $result['how'], 'reason' => $result['reason'], 'wamid' => $result['wamid']]);

            if (in_array($result['how'], ['text', 'template'], true)) {
                Audit::record('morning_summary.sent', 'Morning summary sent', subject: $hotel, properties: ['user_id' => $user->getKey(), 'how' => $result['how'], 'quiet' => $figures['quiet'], 'money' => $money]);
                $sent++;
                $this->waitingRequests($setting);
            }
        }

        return $sent;
    }

    /**
     * The requests still waiting, as cards with Accept and Decline, for
     * those who answer them from WhatsApp.
     */
    private function waitingRequests(StaffAlertSettings $setting): void
    {
        $user = $setting->user;

        if ($user === null || ! $setting->approvesOnWhatsApp($user)) {
            return;
        }

        $waiting = BookingRequest::query()->with(['guest', 'category'])
            ->where('status', BookingRequestStatus::Pending->value)->where('expires_at', '>', now())
            ->orderBy('expires_at')->limit(self::MAX_CARDS)->get();

        foreach ($waiting as $request) {
            $this->alerts->sendTo($setting, $user, AlertKind::NewRequest, (string) $request->reference, $request->id, new BookingRequestCard($request), repeat: true);
        }
    }
}
