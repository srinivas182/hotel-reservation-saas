<?php

declare(strict_types=1);

namespace App\Domains\ChatKit\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\ChatKit\ChatKit;
use App\Domains\ChatKit\Models\ChatKitSettings;
use App\Domains\ChatKit\Models\ChatSource;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The WhatsApp kit page (Sprint H): sources with their link, QR code and
 * poster, the website button, the texts for Google and social pages, and
 * what each source brought this month.
 */
final class KitController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ChatKit $kit,
    ) {}

    public function index(): Response
    {
        $this->kit->ensureDefaults();
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $settings = ChatKitSettings::current();
        $month = HotelClock::today($hotel)->startOfMonth();
        $results = $this->kit->results($month->shiftTimezone($hotel->timezone)->utc(), now());
        $button = ChatSource::query()->where('kind', 'button')->where('active', true)->orderBy('id')->first();
        $google = ChatSource::query()->where('kind', 'google')->orderBy('id')->first();
        $social = ChatSource::query()->whereIn('kind', ['facebook', 'instagram'])->orderBy('id')->get();

        return Inertia::render('hotel/whatsapp-kit', [
            'number' => $this->kit->number(),
            'locales' => HotelSettings::current()->enabled_locales ?? [$locale],
            'intents' => array_map(fn (string $i): array => ['value' => $i, 'label' => ChatKit::greeting($i, $locale)], ChatKit::INTENTS),
            'kinds' => ChatSource::KINDS,
            'sources' => ChatSource::query()->orderBy('id')->get()->map(fn (ChatSource $s): array => [
                'id' => $s->id,
                'code' => $s->code,
                'kind' => $s->kind,
                'name' => $s->name,
                'intent' => $s->intent,
                'active' => $s->active,
                'clicks' => $s->clicks,
                'chats' => $results[$s->id]['chats'] ?? 0,
                'bookings' => $results[$s->id]['bookings'] ?? 0,
                'short_url' => $this->kit->shortUrl($s),
                'whatsapp_url' => $this->kit->whatsappUrl($s, $locale),
                'greeting' => $this->kit->prefilled($s, $locale),
            ])->values(),
            'settings' => [
                'color' => $settings->color,
                'bubble' => (object) ($settings->bubble ?? []),
                'online_from' => $settings->online_from,
                'online_until' => $settings->online_until,
            ],
            'logoUrl' => $settings->logo_path === null ? null : route('hotel.whatsapp-kit.logo'),
            'snippet' => '<script src="'.route('kit.button', $hotel->slug).'" defer></script>',
            'google' => $google === null ? null : $this->kit->shortUrl($google),
            'social' => $social->map(fn (ChatSource $s): array => [
                'name' => $s->name,
                'url' => $this->kit->shortUrl($s),
                'text' => (string) __('Book your room on WhatsApp in 2 minutes, answer 24/7: :url', ['url' => $this->kit->shortUrl($s)], $locale),
            ])->values(),
            'monthLabel' => $month->settings(['locale' => $locale])->isoFormat('MMMM YYYY'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(ChatSource::KINDS)],
            'name' => ['required', 'string', 'max:80'],
            'intent' => ['required', Rule::in(ChatKit::INTENTS)],
        ]);

        $source = ChatSource::query()->create([...$data, 'code' => $this->kit->nextCode((string) $data['kind'])]);
        Audit::record('chat_kit.source_created', 'WhatsApp kit source added', subject: $source, causer: $request->user(), properties: ['code' => $source->code]);

        return back();
    }

    public function update(Request $request, ChatSource $source): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'intent' => ['required', Rule::in(ChatKit::INTENTS)],
            'active' => ['required', 'boolean'],
        ]);

        $source->update($data);

        return back();
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'bubble' => ['nullable', 'array'],
            'bubble.*' => ['nullable', 'string', 'max:80'],
            'online_from' => ['required', 'date_format:H:i'],
            'online_until' => ['required', 'date_format:H:i'],
        ]);

        ChatKitSettings::current()->fill([
            ...$data,
            'bubble' => array_filter(array_map(fn ($t): string => trim((string) $t), $data['bubble'] ?? []), fn (string $t): bool => $t !== ''),
        ])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Kit saved.')]);

        return back();
    }

    public function logo(Request $request): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:jpeg,png', 'max:2048', 'dimensions:max_width=3000,max_height=3000']]);
        $file = $request->file('logo');
        abort_if($file === null || is_array($file), 422);

        $settings = ChatKitSettings::current();
        if ($settings->logo_path !== null) {
            Storage::disk('local')->delete($settings->logo_path);
        }
        $path = 'hotels/'.$this->context->id().'/kit/'.Str::uuid().'.'.$file->extension();
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        $settings->forceFill(['logo_path' => $path])->save();

        return back();
    }

    public function showLogo(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $path = ChatKitSettings::current()->logo_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function removeLogo(): RedirectResponse
    {
        $settings = ChatKitSettings::current();
        if ($settings->logo_path !== null) {
            Storage::disk('local')->delete($settings->logo_path);
        }
        $settings->forceFill(['logo_path' => null])->save();

        return back();
    }

    /** The QR code of a source's short link, as SVG (for any printer). */
    public function qr(ChatSource $source): HttpResponse
    {
        return response(ChatKit::qrSvg($this->kit->shortUrl($source), 800), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="whatsapp-'.Str::slug($source->name).'.svg"',
        ]);
    }

    /**
     * A print-ready poster: A4 for a wall, or an A5 table tent folded in
     * two (the same face twice, back to back).
     */
    public function poster(Request $request, ChatSource $source): HttpResponse
    {
        $data = $request->validate(['format' => ['nullable', Rule::in(['a4', 'a5'])], 'lang' => ['nullable', Rule::in(['fr', 'en'])]]);
        $hotel = $this->context->hotel();
        $format = $data['format'] ?? 'a4';
        $locale = $data['lang'] ?? $hotel->default_locale;
        $settings = ChatKitSettings::current();
        $url = $this->kit->shortUrl($source, $locale);
        $logo = $settings->logo_path !== null && Storage::disk('local')->exists($settings->logo_path)
            ? 'data:'.(Storage::disk('local')->mimeType($settings->logo_path) ?: 'image/png').';base64,'.base64_encode((string) Storage::disk('local')->get($settings->logo_path))
            : null;

        Audit::record('chat_kit.poster', 'WhatsApp poster downloaded', subject: $source, causer: $request->user(), properties: ['format' => $format, 'lang' => $locale]);

        return Pdf::loadView('chat-kit.poster', [
            'hotel' => $hotel,
            'format' => $format,
            'locale' => $locale,
            'color' => $settings->color,
            'logo' => $logo,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode(ChatKit::qrSvg($url, 600)),
            'url' => preg_replace('#^https?://#', '', $url),
            'number' => $this->kit->number(),
            'intent' => $source->intent,
        ])->setPaper($format === 'a5' ? 'a4' : 'a4', $format === 'a5' ? 'landscape' : 'portrait')
            ->download('whatsapp-'.Str::slug($source->name).'-'.$format.'-'.$locale.'.pdf');
    }
}
