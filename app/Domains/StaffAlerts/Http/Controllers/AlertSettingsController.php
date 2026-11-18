<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts\Http\Controllers;

use App\Domains\Access\StaffService;
use App\Domains\Audit\Audit;
use App\Domains\Bookings\GuestDirectory;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlert;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Each person's WhatsApp alerts (and, for owners, the daily summary).
 */
final class AlertSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffService $staff,
    ) {}

    public function show(#[CurrentUser] User $user): Response
    {
        $hotel = $this->context->hotel();
        $settings = StaffAlertSettings::for($user);
        $isOwner = $this->staff->isOwner($hotel, $user);

        return Inertia::render('hotel/my-alerts', [
            'settings' => [
                'alert_phone' => $settings->alert_phone ?? $user->phone_e164 ?? '',
                'alerts' => $settings->alerts ?? [],
                'summary_time' => $settings->summary_time ?? '21:00',
                'summary_channels' => $settings->summary_channels ?? [],
                'approve_by_whatsapp' => $settings->approvesOnWhatsApp($user),
                'morning_on' => $settings->morning_time !== null,
                'morning_time' => $settings->morning_time ?? '07:30',
                'morning_quiet_days' => $settings->morning_quiet_days,
                'push_enabled' => $settings->push_enabled,
                'quiet_on' => $settings->quiet_from !== null,
                'quiet_from' => $settings->quiet_from ?? '22:00',
                'quiet_to' => $settings->quiet_to ?? '06:00',
            ],
            // Sprint D: this person's phones with app notifications.
            'devices' => \App\Domains\Push\Models\PushSubscription::query()->where('user_id', $user->getKey())->latest('id')->get()
                ->map(fn (\App\Domains\Push\Models\PushSubscription $d): array => ['id' => $d->id, 'device' => $d->device, 'since' => $d->created_at?->toIso8601String(), 'last' => $d->last_success_at?->toIso8601String()])->all(),
            'morningMoney' => \App\Domains\StaffAlerts\Morning\MorningSummaryActions::canSeeMoney($user),
            'canDecide' => $user->can('booking_requests.decide'),
            'kinds' => collect(AlertKind::choosable())
                ->filter(fn (AlertKind $kind): bool => $user->can($kind->permission()->value))
                ->map(fn (AlertKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()])->values(),
            'isOwner' => $isOwner,
            'team' => $isOwner ? StaffAlertSettings::query()->with('user')->get()->map(fn (StaffAlertSettings $s): array => [
                'name' => $s->user?->name,
                'phone' => $s->alert_phone,
                'alerts' => array_map(fn (string $k): string => AlertKind::tryFrom($k)?->label() ?? $k, $s->alerts ?? []),
            ])->values() : [],
            'recent' => StaffAlert::query()->where('user_id', $user->getKey())->latest('id')->limit(10)->get()->map(fn (StaffAlert $a): array => [
                'kind' => AlertKind::tryFrom($a->kind)?->label() ?? $a->kind,
                'how' => $a->how,
                'reason' => $a->reason,
                'at' => $a->created_at?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
            ])->values(),
        ]);
    }

    public function update(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $isOwner = $this->staff->isOwner($this->context->hotel(), $user);

        $data = $request->validate([
            'alert_phone' => ['nullable', 'string', 'max:25', 'regex:/^\+?[0-9\s\-().]{7,24}$/'],
            'alerts' => ['array'],
            'alerts.*' => [Rule::in(array_map(fn (AlertKind $k): string => $k->value, AlertKind::choosable()))],
            'summary_time' => ['nullable', 'date_format:H:i'],
            'summary_channels' => ['array'],
            'summary_channels.*' => [Rule::in(['whatsapp', 'email'])],
            'approve_by_whatsapp' => ['nullable', 'boolean'],
            'morning_on' => ['nullable', 'boolean'],
            'morning_time' => ['nullable', 'date_format:H:i'],
            'morning_quiet_days' => ['nullable', 'boolean'],
            'push_enabled' => ['nullable', 'boolean'],
            'quiet_on' => ['nullable', 'boolean'],
            'quiet_from' => ['nullable', 'date_format:H:i'],
            'quiet_to' => ['nullable', 'date_format:H:i'],
        ], ['alert_phone.regex' => __('Use the international format, for example +2250700000000.')]);

        $alerts = array_values(array_filter(
            array_unique((array) ($data['alerts'] ?? [])),
            fn (string $k): bool => $user->can(AlertKind::from($k)->permission()->value),
        ));

        $settings = StaffAlertSettings::for($user);
        $settings->fill([
            'alert_phone' => GuestDirectory::normalisePhone($data['alert_phone'] ?? null),
            'alerts' => $alerts,
            'summary_time' => $isOwner ? ($data['summary_time'] ?? null) : null,
            'summary_channels' => $isOwner ? array_values(array_unique((array) ($data['summary_channels'] ?? []))) : [],
            // Sprint B: only someone who decides requests can choose to answer them from WhatsApp.
            'approve_by_whatsapp' => $user->can('booking_requests.decide') && array_key_exists('approve_by_whatsapp', $data) && $data['approve_by_whatsapp'] !== null ? (bool) $data['approve_by_whatsapp'] : $settings->approve_by_whatsapp,
            // Sprint C: anyone with a phone can ask for the morning summary; money lines follow their permissions.
            'morning_time' => (bool) ($data['morning_on'] ?? false) ? ($data['morning_time'] ?? '07:30') : null,
            'morning_quiet_days' => (bool) ($data['morning_quiet_days'] ?? $settings->morning_quiet_days),
            'push_enabled' => (bool) ($data['push_enabled'] ?? $settings->push_enabled),
            'quiet_from' => (bool) ($data['quiet_on'] ?? false) ? ($data['quiet_from'] ?? '22:00') : null,
            'quiet_to' => (bool) ($data['quiet_on'] ?? false) ? ($data['quiet_to'] ?? '06:00') : null,
        ])->save();

        Audit::record('staff_alerts.settings_updated', 'Alert settings updated', subject: $this->context->hotel(), causer: $user, properties: [
            'alerts' => $alerts,
            'phone_set' => $settings->alert_phone !== null,
            'summary_channels' => $settings->summary_channels,
            'approve_by_whatsapp' => $settings->approve_by_whatsapp,
            'morning_time' => $settings->morning_time,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert settings saved.')]);

        return back();
    }

    public function test(StaffAlerts $alerts, #[CurrentUser] User $user): RedirectResponse
    {
        $settings = StaffAlertSettings::for($user);

        if ($settings->alert_phone === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save your phone number first.')]);

            return back();
        }

        $ok = $alerts->sendTo($settings, $user, AlertKind::Test, __('This is a test. Alerts will look like this.'));

        $last = StaffAlert::query()->where('user_id', $user->getKey())->latest('id')->first();
        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => __('Test alert sent.')]
            : ['type' => 'error', 'message' => __('The test alert was not sent: :reason', ['reason' => (string) $last?->reason])]);

        return back();
    }
}
