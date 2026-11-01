<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Webhooks;

use App\Domains\WhatsApp\Models\WebhookEvent;

/**
 * Verifies and stores Meta webhook deliveries. Each change in a delivery
 * becomes one stored event, processed in the background.
 */
final class WebhookIntake
{
    public function signatureIsValid(string $rawBody, ?string $signature): bool
    {
        $secret = config('whatsapp.app_secret');

        if (! is_string($secret) || $secret === '' || ! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<WebhookEvent>
     */
    public function store(array $payload): array
    {
        $events = [];

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $wabaId = is_scalar($entry['id'] ?? null) ? (string) $entry['id'] : null;

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];

                $events[] = WebhookEvent::query()->create([
                    'received_at' => now(),
                    'phone_number_id' => is_scalar($metadata['phone_number_id'] ?? null) ? (string) $metadata['phone_number_id'] : null,
                    'waba_id' => $wabaId,
                    'kind' => is_string($change['field'] ?? null) ? $change['field'] : 'unknown',
                    'payload' => $value,
                ]);
            }
        }

        return $events;
    }
}
