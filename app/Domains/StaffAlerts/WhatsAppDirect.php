<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts;

use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\WhatsAppContact;
use App\Domains\Messaging\Usage\UsageLedger;
use App\Domains\WhatsApp\Graph\GraphClient;
use App\Domains\WhatsApp\Graph\GraphException;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;

/**
 * Sends WhatsApp messages to staff and owners from the hotel's number.
 * These are not guest conversations, so they are not stored in the inbox:
 * free text if the person wrote to the hotel number in the last 24 hours,
 * otherwise the approved template.
 */
final class WhatsAppDirect
{
    public function __construct(
        private readonly GraphClient $graph,
        private readonly UsageLedger $ledger,
    ) {}

    /**
     * @param  list<string>  $templateValues
     * @return array{how: string, wamid: string|null, reason: string|null}
     */
    public function send(string $phone, string $text, string $templateName, array $templateValues, string $locale, string $fallbackLocale, string $usageSourceId): array
    {
        $account = WhatsAppAccount::query()->first();

        if ($account === null || $account->token === null || ! $account->status->canSend()) {
            return ['how' => 'skipped', 'wamid' => null, 'reason' => 'whatsapp_not_connected'];
        }

        $to = ltrim($phone, '+');
        $contact = WhatsAppContact::query()->where('wa_id', $to)->first();
        $windowOpen = $contact?->last_inbound_at !== null && $contact->last_inbound_at->gt(now()->subHours(Conversation::WINDOW_HOURS));

        try {
            if ($windowOpen) {
                $wamid = $this->graph->sendMessage($account->phone_number_id, $account->token, $to, ['type' => 'text', 'text' => ['body' => $text, 'preview_url' => false]]);
                $this->ledger->record('whatsapp.service', 1, 'staff_alert', $usageSourceId);

                return ['how' => 'text', 'wamid' => $wamid, 'reason' => null];
            }

            $template = MessageTemplate::query()->where('name', $templateName)->where('status', TemplateStatus::Approved->value)->where('language', $locale)->first()
                ?? MessageTemplate::query()->where('name', $templateName)->where('status', TemplateStatus::Approved->value)->where('language', $fallbackLocale)->first();

            if ($template === null) {
                return ['how' => 'skipped', 'wamid' => null, 'reason' => 'template_not_approved'];
            }

            $values = array_map(fn (string $v): string => MessageTemplate::cleanValue($v), $templateValues);
            $wamid = $this->graph->sendMessage($account->phone_number_id, $account->token, $to, [
                'type' => 'template',
                'template' => [
                    'name' => $template->name,
                    'language' => ['code' => $template->language],
                    'components' => [['type' => 'body', 'parameters' => array_map(fn (string $v): array => ['type' => 'text', 'text' => $v], $values)]],
                ],
            ]);
            $this->ledger->record('whatsapp.template.'.strtolower($template->category), 1, 'staff_alert', $usageSourceId);

            return ['how' => 'template', 'wamid' => $wamid, 'reason' => null];
        } catch (GraphException $exception) {
            return ['how' => 'failed', 'wamid' => null, 'reason' => mb_substr($exception->getMessage(), 0, 190)];
        }
    }
}
