<?php

declare(strict_types=1);

namespace App\Domains\Arrivals\Http\Controllers;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Arrivals\ArrivalExtras;
use App\Domains\Audit\Audit;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\StayMessages\StayMessageKind;
use App\Domains\StayMessages\StayMessenger;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The arrival card's settings (Sprint G): local directions, the transport
 * tip, when reception closes and what late guests do, the extras offered
 * and a photo of the entrance.
 */
final class ArrivalSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        $settings = HotelAssistantSettings::current();
        $place = HotelSettings::current();
        $hotel = $this->context->hotel();
        $reminder = StayMessenger::settings($settings)[StayMessageKind::Reminder->value];

        return Inertia::render('hotel/arrival-settings', [
            'locales' => $place->enabled_locales ?? [$hotel->default_locale],
            'reminder' => $reminder,
            'hasPin' => $place->latitude !== null && $place->longitude !== null,
            'mapUrl' => $place->map_url,
            'currency' => $hotel->currency,
            'settings' => [
                'arrival_directions' => (object) ($settings->arrival_directions ?? []),
                'arrival_tip' => (object) ($settings->arrival_tip ?? []),
                'night_arrival' => (object) ($settings->night_arrival ?? []),
                'reception_closes_at' => $settings->reception_closes_at,
                'arrival_extras' => collect(ArrivalExtras::KEYS)->mapWithKeys(fn (string $key): array => [$key => [
                    'enabled' => (bool) ($settings->arrival_extras[$key]['enabled'] ?? false),
                    'price_minor' => $settings->arrival_extras[$key]['price_minor'] ?? null,
                    'label' => ArrivalExtras::label($key),
                ]]),
            ],
            'photoUrl' => $settings->arrival_photo_path === null ? null : route('arrivals.entrance', $hotel->slug).'?v='.substr(md5($settings->arrival_photo_path), 0, 8),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'arrival_directions' => ['nullable', 'array'],
            'arrival_directions.*' => ['nullable', 'string', 'max:400'],
            'arrival_tip' => ['nullable', 'array'],
            'arrival_tip.*' => ['nullable', 'string', 'max:300'],
            'night_arrival' => ['nullable', 'array'],
            'night_arrival.*' => ['nullable', 'string', 'max:400'],
            'reception_closes_at' => ['nullable', 'date_format:H:i'],
            'arrival_extras' => ['nullable', 'array'],
            'arrival_extras.*.enabled' => ['boolean'],
            'arrival_extras.*.price_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $clean = fn (?array $texts): array => array_filter(array_map(fn ($t): string => trim((string) $t), $texts ?? []), fn (string $t): bool => $t !== '');
        $extras = [];
        foreach (ArrivalExtras::KEYS as $key) {
            $extras[$key] = [
                'enabled' => (bool) ($data['arrival_extras'][$key]['enabled'] ?? false),
                'price_minor' => isset($data['arrival_extras'][$key]['price_minor']) ? (int) $data['arrival_extras'][$key]['price_minor'] : null,
            ];
        }

        $settings = HotelAssistantSettings::current();
        $settings->fill([
            'arrival_directions' => $clean($data['arrival_directions'] ?? null),
            'arrival_tip' => $clean($data['arrival_tip'] ?? null),
            'night_arrival' => $clean($data['night_arrival'] ?? null),
            'reception_closes_at' => $data['reception_closes_at'] ?? null,
            'arrival_extras' => $extras,
        ])->save();

        Audit::record('arrivals.settings_saved', 'Arrival card settings saved', subject: $settings, causer: $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Arrival settings saved.')]);

        return back();
    }

    public function photo(Request $request): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,png', 'max:3072', 'dimensions:max_width=4000,max_height=4000']]);
        $file = $request->file('photo');
        abort_if($file === null || is_array($file), 422);

        $settings = HotelAssistantSettings::current();
        $this->deletePhoto($settings);
        // WhatsApp image headers take JPEG or PNG.
        $path = 'hotels/'.$this->context->id().'/arrival/'.Str::uuid().'.'.$file->extension();
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        $settings->forceFill(['arrival_photo_path' => $path])->save();

        Audit::record('arrivals.photo_saved', 'Entrance photo saved', subject: $settings, causer: $request->user());

        return back();
    }

    public function removePhoto(Request $request): RedirectResponse
    {
        $settings = HotelAssistantSettings::current();
        $this->deletePhoto($settings);
        $settings->forceFill(['arrival_photo_path' => null])->save();

        return back();
    }

    /** The entrance photo, public: WhatsApp fetches it for the card. */
    public function entrance(string $hotel, TenantContext $context): StreamedResponse
    {
        $found = Hotel::query()->where('slug', $hotel)->where('status', 'active')->firstOrFail();

        return $context->run($found, function (): StreamedResponse {
            $path = HotelAssistantSettings::current()->arrival_photo_path;
            abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

            return Storage::disk('local')->response($path, null, ['Cache-Control' => 'public, max-age=3600']);
        });
    }

    private function deletePhoto(HotelAssistantSettings $settings): void
    {
        if ($settings->arrival_photo_path !== null) {
            Storage::disk('local')->delete($settings->arrival_photo_path);
        }
    }
}
