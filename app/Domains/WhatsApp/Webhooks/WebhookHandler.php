<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Webhooks;

use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;

/**
 * Handles one kind of webhook change for a hotel's account. Runs with the
 * hotel as the current hotel.
 */
interface WebhookHandler
{
    /**
     * @return list<string> webhook "field" values this handler processes
     */
    public function kinds(): array;

    public function handle(WebhookEvent $event, WhatsAppAccount $account): void;
}
