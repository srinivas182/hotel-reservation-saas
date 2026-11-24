<?php

declare(strict_types=1);

namespace App\Domains\ChatKit\Http\Controllers;

use App\Domains\ChatKit\ChatKit;
use App\Domains\ChatKit\Models\ChatKitSettings;
use App\Domains\ChatKit\Models\ChatSource;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The public side of the WhatsApp kit (Sprint H): the short link that
 * counts the click and opens WhatsApp with the greeting, and the website
 * button script. No cookies, no trackers: one counter per source.
 */
final class PublicKitController extends Controller
{
    public function go(Request $request, string $hotel, TenantContext $context, ?string $code = null): View
    {
        $found = Hotel::query()->where('slug', $hotel)->whereIn('status', ['trial', 'active'])->firstOrFail();

        return $context->run($found, function () use ($request, $found, $code): View {
            $kit = app(ChatKit::class);
            $kit->ensureDefaults();
            $source = ChatSource::query()->where('code', strtoupper($code ?? 'L'))->where('active', true)->first()
                ?? ChatSource::query()->where('code', 'L')->first();
            abort_if($source === null, 404);

            $locale = in_array($request->query('lang'), ['fr', 'en'], true) ? (string) $request->query('lang') : $found->default_locale;
            app()->setLocale($locale);

            // Counted without a cookie or anything personal.
            DB::table('chat_sources')->where('id', $source->id)->update(['clicks' => DB::raw('clicks + 1'), 'last_clicked_at' => now()]);

            $settings = HotelSettings::current();

            return view('chat-kit.go', [
                'hotel' => $found,
                'url' => $kit->whatsappUrl($source, $locale),
                'number' => $kit->number(),
                'phone' => $settings->phone_e164,
                'greeting' => $kit->prefilled($source, $locale),
                'color' => ChatKitSettings::current()->color,
            ]);
        });
    }

    /**
     * The website button: one script tag, in the hotel's colours, showing
     * "online" during the hotel's hours (in the hotel's time zone).
     */
    public function button(string $hotel, TenantContext $context): Response
    {
        $found = Hotel::query()->where('slug', $hotel)->whereIn('status', ['trial', 'active'])->firstOrFail();

        $config = $context->run($found, function () use ($found): array {
            $kit = app(ChatKit::class);
            $kit->ensureDefaults();
            $settings = ChatKitSettings::current();
            $source = ChatSource::query()->where('kind', 'button')->where('active', true)->orderBy('id')->first();
            $locales = array_values(array_unique([$found->default_locale, 'fr', 'en']));
            $texts = [];
            foreach ($locales as $locale) {
                $texts[$locale] = [
                    'label' => (string) __('Chat on WhatsApp', locale: $locale),
                    'online' => (string) __('Online', locale: $locale),
                    'later' => (string) __('Quick reply', locale: $locale),
                    'bubble' => trim((string) ($settings->bubble[$locale] ?? '')) ?: (string) __('A room tonight? Write to us', locale: $locale),
                    'close' => (string) __('Close', locale: $locale),
                ];
            }

            return [
                'url' => $source === null ? null : $kit->shortUrl($source),
                'color' => $settings->color,
                'from' => $settings->online_from,
                'until' => $settings->online_until,
                'tz' => $found->timezone,
                'locale' => $found->default_locale,
                'texts' => $texts,
            ];
        });

        $script = view('chat-kit.button-js', ['config' => $config])->render();

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=600',
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
