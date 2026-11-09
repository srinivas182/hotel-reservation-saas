<?php

declare(strict_types=1);

namespace App\Domains\Demo\Http\Controllers;

use App\Domains\Demo\DemoHotels;
use App\Domains\Demo\DemoScenarios;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Graph\GraphClient;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The demo screen inside a demo hotel: scenarios (A), "your hotel" (B),
 * "try it on your phone" (D) and reset.
 */
final class HotelDemoController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): Response
    {
        $hotel = $this->demoHotel();
        $account = WhatsAppAccount::query()->first();
        $real = $account !== null && ! GraphClient::isDemo($account->phone_number_id);
        $digits = $real ? preg_replace('/\D+/', '', (string) $account->display_phone) : null;

        return Inertia::render('hotel/demo', [
            'hotel' => $hotel->name,
            'expires' => $hotel->demo_expires_at?->toDateTimeString(),
            'scenarios' => collect(DemoScenarios::all())->map(fn (array $s, string $key): array => ['key' => $key, 'label' => $s['label']])->values(),
            'phone' => $real ? self::phone((string) $account->display_phone, 'https://wa.me/'.$digits.'?text='.rawurlencode('Bonjour, avez-vous une chambre ce soir ?')) : null,
            'categories' => DemoHotels::CATEGORIES,
        ]);
    }

    public function play(Request $request, DemoScenarios $scenarios): JsonResponse
    {
        $hotel = $this->demoHotel();
        $data = $request->validate(['scenario' => ['nullable', 'string'], 'text' => ['nullable', 'string', 'max:500']]);
        $all = DemoScenarios::all();

        if (isset($data['scenario']) && isset($all[$data['scenario']])) {
            $s = $all[$data['scenario']];
            $waId = $scenarios->play($hotel, $s['text'], $s['from'], $s['name']);
        } else {
            abort_if(trim((string) ($data['text'] ?? '')) === '', 422);
            $waId = $scenarios->play($hotel, trim((string) $data['text']), 'random', __('Demo guest'));
        }

        return response()->json(['wa' => $waId]);
    }

    public function feed(Request $request, DemoScenarios $scenarios): JsonResponse
    {
        return response()->json(['conversation' => $scenarios->feed($this->demoHotel(), $request->query('wa') === null ? null : (string) $request->query('wa'))]);
    }

    /** B: the prospect's own hotel name, room types and prices. */
    public function personalise(Request $request, DemoHotels $demos): RedirectResponse
    {
        $hotel = $this->demoHotel();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'categories' => ['required', 'array', 'min:1', 'max:6'],
            'categories.*.name' => ['required', 'string', 'max:60'],
            'categories.*.price' => ['required', 'integer', 'min:1000', 'max:10000000'],
            'categories.*.rooms' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        $categories = [];
        foreach (array_values((array) $data['categories']) as $i => $c) {
            $letters = strtoupper(substr((string) preg_replace('/[^A-Za-z]/', '', (string) $c['name']), 0, 3));
            $categories[] = [($letters === '' ? 'C' : $letters).$i, (string) $c['name'], (string) $c['name'], (int) $c['price'], (int) $c['rooms']];
        }
        $this->context->forget();
        $demos->reset($hotel, $categories, (string) $data['name']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('The demo now shows :hotel.', ['hotel' => (string) $data['name']])]);

        return to_route('hotel.demo');
    }

    public function reset(DemoHotels $demos): RedirectResponse
    {
        $hotel = $this->demoHotel();
        $this->context->forget();
        $demos->reset($hotel);

        return to_route('hotel.demo');
    }

    /**
     * D: the link and its QR code (drawn here: no outside service sees it).
     *
     * @return array{display: string, link: string, qr: string}
     */
    private static function phone(string $display, string $link): array
    {
        $svg = (new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(new \BaconQrCode\Renderer\RendererStyle\RendererStyle(180, 1), new \BaconQrCode\Renderer\Image\SvgImageBackEnd)))->writeString($link);

        return ['display' => $display, 'link' => $link, 'qr' => 'data:image/svg+xml;base64,'.base64_encode($svg)];
    }

    private function demoHotel(): Hotel
    {
        $hotel = $this->context->hotel();
        abort_unless($hotel->is_demo, 404);

        return $hotel;
    }
}
