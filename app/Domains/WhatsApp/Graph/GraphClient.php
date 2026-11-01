<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Graph;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the parts of Meta's Graph API this platform uses.
 * All calls are server to server; tokens never reach the browser.
 */
class GraphClient
{
    /**
     * Exchange the code returned by Embedded Signup for the hotel's
     * business access token.
     */
    public function exchangeCode(string $code): string
    {
        $data = $this->json($this->http()->get($this->url('oauth/access_token'), [
            'client_id' => config('whatsapp.app_id'),
            'client_secret' => config('whatsapp.app_secret'),
            'code' => $code,
        ]));

        $token = $data['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new GraphException('Meta did not return an access token.');
        }

        return $token;
    }

    /**
     * Register a number for Cloud API use with a two-step verification PIN.
     */
    public function registerPhone(string $phoneNumberId, string $token, string $pin): void
    {
        $this->json($this->http($token)->post($this->url("{$phoneNumberId}/register"), [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ]));
    }

    /**
     * Subscribe our app to the WhatsApp Business account's webhooks.
     */
    public function subscribeApp(string $wabaId, string $token): void
    {
        $this->json($this->http($token)->post($this->url("{$wabaId}/subscribed_apps")));
    }

    /**
     * @return array<string, mixed>
     */
    public function phoneNumber(string $phoneNumberId, string $token): array
    {
        return $this->json($this->http($token)->get($this->url($phoneNumberId), [
            'fields' => 'display_phone_number,verified_name,quality_rating,throughput,messaging_limit_tier,status',
        ]));
    }

    public function url(string $path): string
    {
        return rtrim((string) config('whatsapp.graph_url'), '/').'/'.config('whatsapp.graph_version').'/'.ltrim($path, '/');
    }

    private function http(?string $token = null): PendingRequest
    {
        $request = Http::acceptJson()->timeout(15)->retry(2, 500, throw: false);

        return $token === null ? $request : $request->withToken($token);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];

        if ($response->failed() || isset($data['error'])) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];

            throw new GraphException(
                (string) ($error['message'] ?? 'Meta request failed.'),
                (int) ($error['code'] ?? 0),
                $response->status(),
            );
        }

        return $data;
    }
}
