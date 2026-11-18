<?php

declare(strict_types=1);

namespace App\Domains\Push\Http\Controllers;

use App\Domains\Push\PushLinks;
use App\Domains\StaffActions\StaffActionRouter;
use App\Domains\StaffAlerts\StaffAlert;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Called by the service worker when a notification is tapped (Sprint D).
 * There is no session or CSRF token there: the signed code in the
 * notification is the proof, as for WhatsApp buttons.
 */
final class PushActionController extends Controller
{
    public function act(Request $request, TenantContext $context, StaffActionRouter $router): JsonResponse
    {
        $data = $request->validate(['hotel' => ['required', 'string', 'max:40'], 'token' => ['required', 'string', 'max:200']]);
        $hotel = Hotel::query()->whereKey((string) $data['hotel'])->first();

        if ($hotel === null) {
            return response()->json(['replies' => []], 404);
        }

        $replies = $context->run($hotel, fn (): array => $router->handlePush((string) $data['token']));

        return response()->json(['replies' => $replies]);
    }

    public function opened(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate(['hotel' => ['required', 'string', 'max:40'], 'alert' => ['required', 'integer'], 'signature' => ['required', 'string', 'size:32']]);
        $hotel = Hotel::query()->whereKey((string) $data['hotel'])->first();

        if ($hotel === null || ! hash_equals(PushLinks::openedSignature($hotel->id, (int) $data['alert']), (string) $data['signature'])) {
            return response()->json(['ok' => false], 404);
        }

        $context->run($hotel, fn () => StaffAlert::query()->whereKey((int) $data['alert'])->whereNull('opened_at')->update(['opened_at' => now()]));

        return response()->json(['ok' => true]);
    }
}
