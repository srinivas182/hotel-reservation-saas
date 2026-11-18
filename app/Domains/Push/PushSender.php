<?php

declare(strict_types=1);

namespace App\Domains\Push;

use App\Domains\Push\Models\PushSubscription;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

/**
 * Sends app notifications to a person's phones in the current hotel and
 * keeps the list clean: unsubscribed phones are removed, so are phones
 * that keep failing.
 */
final class PushSender
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PushTransport $transport,
    ) {}

    public function available(): bool
    {
        return $this->transport->configured();
    }

    public function hasDevices(User $user): bool
    {
        return $this->available() && PushSubscription::query()->where('user_id', $user->getKey())->exists();
    }

    /**
     * @param  array{title: string, body: string, url?: string, tag?: string, actions?: list<array{action: string, title: string, token?: string}>, alert?: int|null, badge?: int, urgent?: bool}  $payload
     * @return int phones that received it
     */
    public function toUser(User $user, array $payload): int
    {
        $subscriptions = array_values(PushSubscription::query()->where('user_id', $user->getKey())->get()->all());

        if ($subscriptions === [] || ! $this->available()) {
            return 0;
        }

        $payload += ['hotel' => $this->context->id(), 'url' => route('hotel.today'), 'tag' => 'alert', 'badge' => Badge::count()];
        $sent = 0;

        foreach ($this->transport->send($subscriptions, $payload) as $id => $result) {
            $subscription = collect($subscriptions)->firstWhere('id', $id);
            if (! $subscription instanceof PushSubscription) {
                continue;
            }

            if ($result === 'sent') {
                $sent++;
                $subscription->forceFill(['last_success_at' => now(), 'failures' => 0])->save();
            } elseif ($result === 'gone' || $subscription->failures + 1 >= PushSubscription::MAX_FAILURES) {
                $subscription->delete();
            } else {
                $subscription->forceFill(['failures' => $subscription->failures + 1])->save();
            }
        }

        return $sent;
    }
}
