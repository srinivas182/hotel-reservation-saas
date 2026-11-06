<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Http\Controllers;

use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Restaurant\Menu\MenuCatalog;
use App\Domains\Restaurant\Menu\MenuItem;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public, read-only menu page of an outlet (linked from WhatsApp and
 * printed as a QR code). No login, no personal data.
 */
final class PublicMenuController extends Controller
{
    public function show(Request $request, string $hotelSlug, string $outletSlug, TenantContext $context, MenuCatalog $catalog): View
    {
        $hotel = Hotel::query()->where('slug', $hotelSlug)->where('status', 'active')->firstOrFail();

        return $context->run($hotel, function () use ($request, $hotel, $outletSlug, $catalog): View {
            $outlet = Outlet::query()->where('public_slug', $outletSlug)->where('active', true)->firstOrFail();
            $enabled = HotelSettings::current()->enabled_locales ?? [$hotel->default_locale];
            $locale = in_array($request->query('lang'), $enabled, true) ? (string) $request->query('lang') : $hotel->default_locale;
            app()->setLocale($locale);

            $menu = $catalog->forGuests($outlet, $locale, allServices: $request->boolean('all'));

            return view('public.menu', [
                'hotel' => $hotel,
                'outlet' => $outlet->localizedName($locale, $hotel->default_locale),
                'outletSlug' => $outlet->public_slug,
                'menu' => $menu,
                'locale' => $locale,
                'locales' => $enabled,
                'all' => $request->boolean('all'),
            ]);
        });
    }

    public function photo(string $hotelSlug, string $outletSlug, int $item, TenantContext $context): StreamedResponse
    {
        $hotel = Hotel::query()->where('slug', $hotelSlug)->where('status', 'active')->firstOrFail();

        return $context->run($hotel, function () use ($outletSlug, $item): StreamedResponse {
            $outlet = Outlet::query()->where('public_slug', $outletSlug)->firstOrFail();
            $dish = MenuItem::query()->whereKey($item)->whereHas('section', fn ($q) => $q->where('outlet_id', $outlet->id))->whereNotNull('photo_path')->firstOrFail();

            return Storage::disk('local')->response((string) $dish->photo_path, null, ['Cache-Control' => 'public, max-age=3600']);
        });
    }
}
