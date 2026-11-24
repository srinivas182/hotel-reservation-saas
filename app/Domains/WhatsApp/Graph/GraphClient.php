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
        if (self::isDemo($phoneNumberId)) {
            return ['display_phone_number' => '+225 00 00 00 00', 'verified_name' => 'Demo', 'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K', 'status' => 'CONNECTED'];
        }

        return $this->json($this->http($token)->get($this->url($phoneNumberId), [
            'fields' => 'display_phone_number,verified_name,quality_rating,throughput,messaging_limit_tier,status',
        ]));
    }

    /**
     * Send a message; returns Meta's message ID.
     *
     * @param  array<string, mixed>  $content  type-specific part, e.g. ['type' => 'text', 'text' => [...]]
     */
    public function sendMessage(string $phoneNumberId, string $token, string $to, array $content): string
    {
        // Demo hotels (S17): nothing leaves the platform; the message shows as sent.
        if (self::isDemo($phoneNumberId)) {
            return 'wamid.DEMO'.bin2hex(random_bytes(10));
        }

        $data = $this->json($this->http($token, retry: false)->post($this->url("{$phoneNumberId}/messages"), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            ...$content,
        ]));

        $id = $data['messages'][0]['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new GraphException('Meta did not return a message ID.', 0, 502);
        }

        return $id;
    }

    /**
     * Mark a guest's message as read and show "typing…" in their chat
     * (0.37.4). WhatsApp hides it when the reply arrives, or after 25
     * seconds. Best effort: never throws.
     */
    public function typing(string $phoneNumberId, string $token, string $wamid): bool
    {
        if (self::isDemo($phoneNumberId)) {
            return true;
        }

        try {
            $response = $this->http($token, retry: false)->timeout(5)->post($this->url("{$phoneNumberId}/messages"), [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $wamid,
                'typing_indicator' => ['type' => 'text'],
            ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Download a media file by its ID (two steps: get a short-lived URL,
     * then fetch it with the same token).
     *
     * @return array{contents: string, mime_type: string}
     */
    public function downloadMedia(string $mediaId, string $token, int $maxBytes): array
    {
        $info = $this->json($this->http($token)->get($this->url($mediaId)));
        $url = $info['url'] ?? null;
        $size = (int) ($info['file_size'] ?? 0);

        if (! is_string($url) || $url === '') {
            throw new GraphException('Meta did not return a download address.');
        }

        if ($size > $maxBytes) {
            throw new GraphException('The file is larger than the limit.', 413, 413);
        }

        $response = $this->http($token)->get($url);

        if ($response->failed()) {
            throw new GraphException('The file could not be downloaded.', 0, $response->status());
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw new GraphException('The file is larger than the limit.', 413, 413);
        }

        return ['contents' => $body, 'mime_type' => (string) ($info['mime_type'] ?? $response->header('Content-Type'))];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array<string, mixed> id, status, category
     */
    public function createTemplate(string $wabaId, string $token, string $name, string $language, string $category, array $components): array
    {
        return $this->json($this->http($token, retry: false)->post($this->url("{$wabaId}/message_templates"), [
            'name' => $name,
            'language' => $language,
            'category' => $category,
            'components' => $components,
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    public function editTemplate(string $templateId, string $token, array $components): array
    {
        return $this->json($this->http($token, retry: false)->post($this->url($templateId), ['components' => $components]));
    }

    public function deleteTemplate(string $wabaId, string $token, string $name, string $templateId): void
    {
        $this->json($this->http($token)->delete($this->url("{$wabaId}/message_templates").'?'.http_build_query(['name' => $name, 'hsm_id' => $templateId])));
    }

    /**
     * Demo hotels' simulated WhatsApp numbers (S17): never sent to Meta.
     */
    public static function isDemo(string $phoneNumberIdOrWaba): bool
    {
        return str_starts_with($phoneNumberIdOrWaba, 'DEMO-');
    }

    /**
     * Simulated numbers, accounts, media and templates ("SIM-…", "sim-…").
     * Real Meta IDs are numeric, so the two never collide.
     */
    public static function isSimulated(string $id): bool
    {
        return stripos($id, 'sim-') === 0;
    }

    /**
     * Address for a Graph path. With simulation on, only simulated IDs go
     * to the simulator; real numbers on the same server still reach Meta.
     */
    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        $base = (string) config('whatsapp.graph_url');
        $simulator = config('whatsapp.simulation_graph_url');

        if (config('simulation.enabled') && is_string($simulator) && $simulator !== '' && self::isSimulated((string) strtok($path, '/?'))) {
            $base = $simulator;
        }

        return rtrim($base, '/').'/'.config('whatsapp.graph_version').'/'.$path;
    }

    private function http(?string $token = null, bool $retry = true): PendingRequest
    {
        // Sends are not retried here: the queued job decides, so a message is never sent twice.
        $request = Http::acceptJson()->timeout(15);
        $request = $retry ? $request->retry(2, 500, throw: false) : $request;

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
