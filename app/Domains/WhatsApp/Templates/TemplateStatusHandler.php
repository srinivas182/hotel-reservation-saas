<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Templates;

use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Webhooks\WebhookHandler;

/**
 * Meta's decisions on templates (approved, rejected, paused…).
 */
final class TemplateStatusHandler implements WebhookHandler
{
    public function kinds(): array
    {
        return ['message_template_status_update'];
    }

    public function handle(WebhookEvent $event, WhatsAppAccount $account): void
    {
        $value = $event->payload;
        $status = TemplateStatus::fromMeta((string) ($value['event'] ?? ''));

        if ($status === null) {
            return;
        }

        $id = is_scalar($value['message_template_id'] ?? null) ? (string) $value['message_template_id'] : null;

        $template = MessageTemplate::query()
            ->when($id !== null, fn ($q) => $q->where('meta_template_id', $id))
            ->when($id === null, fn ($q) => $q
                ->where('name', (string) ($value['message_template_name'] ?? ''))
                ->where('language', (string) ($value['message_template_language'] ?? '')))
            ->first();

        $reason = (string) ($value['reason'] ?? '');

        $template?->forceFill([
            'status' => $status,
            'rejection_reason' => $status === TemplateStatus::Rejected || $status === TemplateStatus::Paused
                ? ($reason !== '' && $reason !== 'NONE' ? $reason : __('Meta did not give a reason.'))
                : null,
        ])->save();
    }
}
