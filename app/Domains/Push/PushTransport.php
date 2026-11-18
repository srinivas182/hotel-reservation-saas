<?php

declare(strict_types=1);

namespace App\Domains\Push;

use App\Domains\Push\Models\PushSubscription;

/**
 * Delivers encrypted notifications to browsers' push services.
 */
interface PushTransport
{
    /**
     * @param  list<PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     * @return array<int, string> subscription id => "sent", "gone" (unsubscribed: remove it) or "failed"
     */
    public function send(array $subscriptions, array $payload): array;

    public function configured(): bool;
}
