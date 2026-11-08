<?php

declare(strict_types=1);

namespace App\Domains\Support;

use App\Domains\Conversations\ConversationDirectory;
use App\Domains\Conversations\MessageSender;
use App\Domains\Support\Models\SupportSettings;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the requester on WhatsApp that support answered, from the support
 * team's own WhatsApp number (an account connected like a hotel's, chosen
 * in Platform → Support). Off until that number and its approved template
 * exist; email always goes out anyway.
 */
final class SupportWhatsApp
{
    public function available(): bool
    {
        return $this->account() !== null;
    }

    public function notify(SupportTicket $ticket): bool
    {
        $phone = $ticket->requester?->phone_e164;
        $account = $this->account();

        if ($phone === null || $account === null) {
            return false;
        }

        try {
            return app(TenantContext::class)->run($account['hotel'], function () use ($ticket, $phone, $account): bool {
                $conversation = app(ConversationDirectory::class)->forWaId(ltrim($phone, '+'), $ticket->requester->name);
                app(MessageSender::class)->sendTemplate($conversation, $account['template'], [(string) $ticket->hotel?->name, (string) $ticket->id]);

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('Support WhatsApp notice failed', ['ticket' => $ticket->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array{hotel: Hotel, template: MessageTemplate}|null
     */
    private function account(): ?array
    {
        $settings = SupportSettings::current();
        $hotel = $settings->whatsapp_hotel_id === null ? null : Hotel::query()->find($settings->whatsapp_hotel_id);

        if ($hotel === null) {
            return null;
        }

        $template = app(TenantContext::class)->run($hotel, fn () => MessageTemplate::query()->where('name', $settings->whatsapp_template)->where('status', TemplateStatus::Approved->value)->first());

        return $template === null ? null : ['hotel' => $hotel, 'template' => $template];
    }
}
