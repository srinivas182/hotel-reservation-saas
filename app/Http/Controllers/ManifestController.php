<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Site\Models\BrandSettings;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest of the staff app (Sprint D), named after the
 * product's brand (Platform → Website).
 */
final class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $name = (string) (BrandSettings::cached()['name'] ?? config('app.name'));

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 15),
            'description' => __('Hotel reservations on WhatsApp: requests, conversations and bookings.'),
            'id' => '/hotel/today',
            'start_url' => '/hotel/today?source=app',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => '#0f766e',
            'lang' => app()->getLocale(),
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => __('Today'), 'url' => '/hotel/today?source=app'],
                ['name' => __('Inbox'), 'url' => '/hotel/inbox?source=app'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }
}
