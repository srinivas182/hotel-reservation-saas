<?php

declare(strict_types=1);

namespace App\Domains\Simulation;

use App\Domains\WhatsApp\Webhooks\ProcessWebhookEvent;
use App\Domains\WhatsApp\Webhooks\WebhookIntake;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Plays Meta's part after a simulated send: "sent", "delivered" and "read"
 * receipts go through the normal webhook processing.
 */
final class SimulateReceipts implements ShouldQueue
{
    use Queueable;

    /** Retry policy (S16): attempts before the job is marked failed. */
    public int $tries = 1;

    /** Seconds before a stuck attempt is stopped. */
    public int $timeout = 60;

    public function __construct(
        public readonly string $phoneNumberId,
        public readonly string $wamid,
        public readonly string $to,
    ) {}

    public function handle(WebhookIntake $intake): void
    {
        $statuses = [];
        foreach (['sent', 'delivered', 'read'] as $offset => $status) {
            $statuses[] = ['id' => $this->wamid, 'status' => $status, 'timestamp' => (string) ((int) now()->timestamp + $offset), 'recipient_id' => $this->to];
        }

        $events = $intake->store(['entry' => [['id' => 'sim-waba', 'changes' => [[
            'field' => 'messages',
            'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $this->phoneNumberId], 'statuses' => $statuses],
        ]]]]]);

        foreach ($events as $event) {
            ProcessWebhookEvent::dispatch($event->id);
        }
    }
}
