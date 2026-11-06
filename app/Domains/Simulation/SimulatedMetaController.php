<?php

declare(strict_types=1);

namespace App\Domains\Simulation;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A fake Meta Graph API for staging: accepts sends (and answers them with
 * delivery and read receipts), serves media, and can inject failures.
 */
final class SimulatedMetaController extends Controller
{
    public function graph(Request $request, string $version, string $path): JsonResponse
    {
        try {
            SimulatedAi::wait('meta');
        } catch (RuntimeException) {
            return response()->json(['error' => ['message' => 'Simulated Meta outage', 'code' => 2, 'type' => 'OAuthException']], 503);
        }

        $segments = explode('/', trim($path, '/'));

        if ($request->isMethod('post') && end($segments) === 'messages') {
            $wamid = 'wamid.SIM'.Str::ulid();
            SimulateReceipts::dispatch((string) $segments[0], $wamid, (string) $request->input('to'))
                ->delay(now()->addSeconds((int) config('simulation.meta.status_delay_seconds')));

            return response()->json(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => $request->input('to')]], 'messages' => [['id' => $wamid]]]);
        }

        if ($request->isMethod('get') && count($segments) === 1 && str_starts_with($segments[0], 'sim-media-')) {
            return response()->json(['url' => url('/simulation/media/'.$segments[0]), 'mime_type' => 'audio/ogg', 'file_size' => 4]);
        }

        // Registration, subscription, templates and the rest: plain success.
        return response()->json(['success' => true, 'id' => 'sim-'.Str::lower((string) Str::ulid())]);
    }

    public function media(string $id): Response
    {
        return response('OggS', 200, ['Content-Type' => 'audio/ogg']);
    }
}
