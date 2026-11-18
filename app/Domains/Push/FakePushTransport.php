<?php

declare(strict_types=1);

namespace App\Domains\Push;

/**
 * Records notifications instead of sending them (tests).
 */
final class FakePushTransport implements PushTransport
{
    /** @var list<array{subscription: int, payload: array<string, mixed>}> */
    public array $sent = [];

    /** @var array<int, string> subscription id => forced result */
    public array $results = [];

    public function configured(): bool
    {
        return true;
    }

    public function send(array $subscriptions, array $payload): array
    {
        $out = [];
        foreach ($subscriptions as $subscription) {
            $result = $this->results[$subscription->id] ?? 'sent';
            if ($result === 'sent') {
                $this->sent[] = ['subscription' => $subscription->id, 'payload' => $payload];
            }
            $out[$subscription->id] = $result;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(): array
    {
        return array_column($this->sent, 'payload');
    }
}
