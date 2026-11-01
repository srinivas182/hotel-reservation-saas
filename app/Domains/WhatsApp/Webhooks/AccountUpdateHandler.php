<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Webhooks;

use App\Domains\Audit\Audit;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;

/**
 * Quality rating, messaging limit and account restriction changes.
 */
final class AccountUpdateHandler implements WebhookHandler
{
    public function kinds(): array
    {
        return ['phone_number_quality_update', 'account_update', 'phone_number_name_update'];
    }

    public function handle(WebhookEvent $event, WhatsAppAccount $account): void
    {
        $value = $event->payload;

        if ($event->kind === 'phone_number_quality_update') {
            $account->forceFill(array_filter([
                'messaging_limit' => is_string($value['current_limit'] ?? null) ? $value['current_limit'] : null,
                'quality_rating' => is_string($value['event'] ?? null) ? $value['event'] : null,
            ]))->save();

            return;
        }

        if ($event->kind === 'phone_number_name_update' && ($value['decision'] ?? null) === 'APPROVED' && is_string($value['requested_verified_name'] ?? null)) {
            $account->forceFill(['verified_name' => $value['requested_verified_name']])->save();

            return;
        }

        if ($event->kind === 'account_update') {
            $restricted = in_array($value['event'] ?? null, ['ACCOUNT_RESTRICTION', 'DISABLED_UPDATE', 'ACCOUNT_VIOLATION'], true);

            if ($restricted) {
                $account->forceFill(['status' => AccountStatus::Restricted, 'last_error' => (string) ($value['event'] ?? '')])->save();
                Audit::record('whatsapp.restricted', 'WhatsApp account restricted by Meta', subject: $account, properties: ['event' => $value['event'] ?? null]);
            }
        }
    }
}
