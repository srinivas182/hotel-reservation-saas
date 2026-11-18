<?php

declare(strict_types=1);

namespace App\Domains\Push;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Web Push (RFC 8030/8291/8292) with VAPID, through minishlink/web-push.
 * The payload is encrypted for each phone; the push service only sees
 * ciphertext.
 */
final class WebPushTransport implements PushTransport
{
    public function configured(): bool
    {
        return filled(config('webpush.public_key')) && filled(config('webpush.private_key'));
    }

    public function send(array $subscriptions, array $payload): array
    {
        if (! $this->configured() || $subscriptions === []) {
            return [];
        }

        $webPush = new WebPush(
            ['VAPID' => ['subject' => (string) config('webpush.subject'), 'publicKey' => (string) config('webpush.public_key'), 'privateKey' => (string) config('webpush.private_key')]],
            ['TTL' => (int) config('webpush.ttl'), 'urgency' => 'high', 'topic' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['tag'] ?? '')) ?? '', 0, 32) ?: null],
            new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]), // a slow push service must not hold the queue
        );
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $byEndpoint = [];

        foreach ($subscriptions as $subscription) {
            $byEndpoint[$subscription->endpoint] = $subscription->id;
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->p256dh,
                'authToken' => $subscription->auth,
                'contentEncoding' => 'aes128gcm',
            ]), $json);
        }

        $results = [];

        try {
            foreach ($webPush->flush() as $report) {
                $id = $byEndpoint[$report->getEndpoint()] ?? null;
                if ($id === null) {
                    continue;
                }
                $results[$id] = $report->isSuccess() ? 'sent' : ($report->isSubscriptionExpired() ? 'gone' : 'failed');
                if (! $report->isSuccess() && ! $report->isSubscriptionExpired()) {
                    Log::warning('Push notification failed', ['reason' => mb_substr($report->getReason(), 0, 200)]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Push notifications could not be sent', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        return $results;
    }
}
