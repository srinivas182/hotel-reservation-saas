<?php

declare(strict_types=1);

namespace App\Domains\Hotel\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Hotel\Http\Requests\HotelSettingsRequest;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Hotel\Support\Locales;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Brick\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class HotelSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function edit(Request $request): Response
    {
        $hotel = $this->context->hotel();
        $settings = HotelSettings::current();

        /** @var \Carbon\CarbonImmutable $now */
        $now = now($hotel->timezone)->locale($hotel->default_locale);

        return Inertia::render('hotel/settings', [
            'hotel' => $hotel->only(['name', 'legal_name', 'timezone', 'currency', 'default_locale']),
            'settings' => [
                ...$settings->only([
                    'phone_e164', 'whatsapp_display_e164', 'email',
                    'address_line1', 'address_line2', 'city', 'map_url',
                ]),
                'check_in_time' => substr($settings->check_in_time, 0, 5),
                'check_out_time' => substr($settings->check_out_time, 0, 5),
                'late_checkout_until' => $settings->late_checkout_until === null ? null : substr($settings->late_checkout_until, 0, 5),
                'staffed_hours' => $settings->staffed_hours ?? (object) [],
                'enabled_locales' => $settings->enabled_locales ?? [$hotel->default_locale],
            ],
            'options' => [
                'locales' => Locales::SUPPORTED,
                'timezones' => timezone_identifiers_list(),
                'days' => HotelSettingsRequest::DAYS,
            ],
            'preview' => [
                'datetime' => $now->isoFormat('LLLL'),
                'money' => MoneyFormatter::format(Money::of(25000, $hotel->currency, roundingMode: \Brick\Math\RoundingMode::HalfUp), $hotel->default_locale),
            ],
            'canManage' => $request->user()?->can('hotel.settings.manage') === true,
        ]);
    }

    public function update(HotelSettingsRequest $request): RedirectResponse
    {
        $hotel = $this->context->hotel();
        $settings = HotelSettings::current();
        $data = $request->validated();

        $currencyChanged = $hotel->currency !== $data['currency'];

        DB::transaction(function () use ($hotel, $settings, $data): void {
            $hotel->update(Arr::only($data, ['name', 'legal_name', 'timezone', 'currency', 'default_locale']));

            $settings->fill(Arr::only($data, [
                'phone_e164', 'whatsapp_display_e164', 'email',
                'address_line1', 'address_line2', 'city', 'map_url',
                'check_in_time', 'check_out_time', 'late_checkout_until',
                'staffed_hours', 'enabled_locales',
            ]));

            $changes = $settings->getDirty();
            $settings->save();

            if ($changes !== []) {
                Audit::record('hotel.settings_updated', 'Hotel settings updated', subject: $hotel, properties: [
                    'changed' => array_keys(Arr::except($changes, ['updated_at'])),
                ]);
            }
        });

        Inertia::flash('toast', [
            'type' => $currencyChanged ? 'warning' : 'success',
            'message' => $currencyChanged
                ? __('Settings saved. The currency changed: check your fixed tax amounts.')
                : __('Settings saved.'),
        ]);

        return back();
    }
}
