<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts;

use App\Domains\Access\StaffService;
use App\Domains\Audit\Audit;
use App\Domains\StaffAlerts\Mail\OwnerSummaryMail;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends each opted-in owner their daily summary once, at their chosen time.
 */
final class OwnerSummarySender
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly OwnerSummary $summary,
        private readonly WhatsAppDirect $whatsapp,
        private readonly StaffService $staff,
    ) {}

    public function sendDue(): int
    {
        $hotel = $this->context->hotel();
        $today = HotelClock::today($hotel);
        $now = CarbonImmutable::now($hotel->timezone)->format('H:i');
        $sent = 0;

        $due = StaffAlertSettings::query()->with('user')
            ->whereNotNull('summary_time')
            ->where('summary_time', '<=', $now)
            ->where(fn ($q) => $q->whereNull('summary_sent_on')->orWhere('summary_sent_on', '<', $today->toDateString()))
            ->get();

        foreach ($due as $setting) {
            $user = $setting->user;
            $channels = $setting->summary_channels ?? [];

            if ($user === null || $channels === [] || ! $this->staff->isOwner($hotel, $user)) {
                continue;
            }

            $locale = $user->preferred_locale ?: $hotel->default_locale;
            $s = $this->summary->build($locale);

            if (in_array('email', $channels, true)) {
                Mail::to($user->email)->locale($locale)->queue(new OwnerSummaryMail($hotel->name, $s));
            }

            if (in_array('whatsapp', $channels, true) && $setting->alert_phone !== null) {
                $requests = "{$s['requests']['approved']}/{$s['requests']['declined']}/{$s['requests']['expired']}";
                $text = __('Summary for :hotel on :date: :arrivals arrivals, :departures departures, :occupied of :rooms rooms occupied tonight. New bookings: :new (:value). WhatsApp requests approved/declined/expired: :requests. Conversations needing attention: :attention. AI cost: :ai.', [
                    'hotel' => $hotel->name, 'date' => $s['date'], 'arrivals' => $s['arrivals'], 'departures' => $s['departures'],
                    'occupied' => $s['occupied'], 'rooms' => $s['rooms'], 'new' => $s['new_bookings'], 'value' => $s['new_value'],
                    'requests' => $requests, 'attention' => $s['attention'], 'ai' => $s['ai_cost'],
                ], $locale);

                $this->whatsapp->send($setting->alert_phone, $text, 'owner_daily_summary', [
                    $hotel->name, $s['date'], (string) $s['arrivals'], (string) $s['departures'], "{$s['occupied']}/{$s['rooms']}",
                    (string) $s['new_bookings'], $s['new_value'], $requests, (string) $s['attention'], $s['ai_cost'],
                ], $locale, $hotel->default_locale, (string) Str::ulid());
            }

            $setting->forceFill(['summary_sent_on' => $today])->save();
            Audit::record('owner_summary.sent', 'Daily summary sent to the owner', subject: $hotel, properties: ['user_id' => $user->getKey(), 'channels' => $channels]);
            $sent++;
        }

        return $sent;
    }
}
