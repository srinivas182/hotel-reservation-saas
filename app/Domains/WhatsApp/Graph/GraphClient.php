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

    /**
     * Send a message; returns Meta's message ID.
     *
     * @param  array<string, mixed>  $content  type-specific part, e.g. ['type' => 'text', 'text' => [...]]
     */
    public function sendMessage(string $phoneNumberId, string $token, string $to, array $content): string
    {
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

    public function url(string $path): string
    {
        return rtrim((string) config('whatsapp.graph_url'), '/').'/'.config('whatsapp.graph_version').'/'.ltrim($path, '/');
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
