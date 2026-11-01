<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Http\Controllers;

use App\Domains\WhatsApp\Webhooks\ProcessWebhookEvent;
use App\Domains\WhatsApp\Webhooks\WebhookIntake;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Meta's webhook endpoint for every hotel.
 */
final class WebhookController extends Controller
{
    /**
     * Meta's verification request when the webhook address is set up.
     */
    public function verify(Request $request): Response
    {
        $token = config('whatsapp.verify_token');

        abort_unless(
            $request->query('hub_mode') === 'subscribe'
            && is_string($token) && $token !== ''
            && hash_equals($token, (string) $request->query('hub_verify_token')),
            403,
        );

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, WebhookIntake $intake): Response
    {
        $raw = (string) $request->getContent();

        abort_unless($intake->signatureIsValid($raw, $request->header('X-Hub-Signature-256')), 401);

        $payload = json_decode($raw, true);

        if (! is_array($payload) || ($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response('IGNORED', 200);
        }

        foreach ($intake->store($payload) as $event) {
            ProcessWebhookEvent::dispatch($event->id);
        }

        Cache::put('whatsapp:last_webhook_at', now()->toIso8601String(), now()->addDays(30));

        return response('EVENT_RECEIVED', 200);
    }
}
