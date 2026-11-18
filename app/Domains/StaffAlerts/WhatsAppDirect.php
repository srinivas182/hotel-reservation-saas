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

        return $this->deliver($account, $phone, ['type' => 'text', 'text' => ['body' => $text, 'preview_url' => false]], $templateName, $templateValues, [], $locale, $fallbackLocale, $usageSourceId);
    }

    /**
     * A message with reply buttons (Sprint B): interactive buttons inside
     * the 24-hour window, otherwise the template with quick-reply buttons
     * carrying the same codes. Without an approved template, "skipped"
     * with reason template_not_approved, so the caller can fall back.
     *
     * @param  list<array{id: string, title: string}>  $buttons  at most 3; titles at most 20 characters
     * @param  list<string>  $templateValues
     * @return array{how: string, wamid: string|null, reason: string|null}
     */
    public function sendButtons(string $phone, string $text, array $buttons, string $templateName, array $templateValues, string $locale, string $fallbackLocale, string $usageSourceId): array
    {
        $account = WhatsAppAccount::query()->first();

        if ($account === null || $account->token === null || ! $account->status->canSend()) {
            return ['how' => 'skipped', 'wamid' => null, 'reason' => 'whatsapp_not_connected'];
        }

        $interactive = [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($text, 0, 1024)],
                'action' => ['buttons' => array_map(fn (array $b): array => ['type' => 'reply', 'reply' => ['id' => $b['id'], 'title' => mb_substr($b['title'], 0, 20)]], array_slice($buttons, 0, 3))],
            ],
        ];

        return $this->deliver($account, $phone, $interactive, $templateName, $templateValues, array_column(array_slice($buttons, 0, 3), 'id'), $locale, $fallbackLocale, $usageSourceId);
    }

    /**
     * Whether the person wrote to (or tapped a button of) the hotel number
     * in the last 24 hours, so a free message can be sent.
     */
    public function windowOpen(string $phone): bool
    {
        $to = ltrim($phone, '+');
        $since = now()->subHours(Conversation::WINDOW_HOURS);
        $contact = WhatsAppContact::query()->where('wa_id', $to)->first();

        if ($contact?->last_inbound_at !== null && $contact->last_inbound_at->gt($since)) {
            return true;
        }

        return StaffAlertSettings::query()->where('alert_phone', '+'.$to)->where('whatsapp_inbound_at', '>', $since)->exists();
    }

    /**
     * @param  array<string, mixed>  $free  the message inside the window
     * @param  list<string>  $templateValues
     * @param  list<string>  $buttonPayloads  quick-reply payloads of the template, in order
     * @return array{how: string, wamid: string|null, reason: string|null}
     */
    private function deliver(WhatsAppAccount $account, string $phone, array $free, string $templateName, array $templateValues, array $buttonPayloads, string $locale, string $fallbackLocale, string $usageSourceId): array
    {
        $to = ltrim($phone, '+');

        try {
            if ($this->windowOpen($phone)) {
                $wamid = $this->graph->sendMessage($account->phone_number_id, (string) $account->token, $to, $free);
                $this->ledger->record('whatsapp.service', 1, 'staff_alert', $usageSourceId);

                return ['how' => 'text', 'wamid' => $wamid, 'reason' => null];
            }

            $template = MessageTemplate::query()->where('name', $templateName)->where('status', TemplateStatus::Approved->value)->where('language', $locale)->first()
                ?? MessageTemplate::query()->where('name', $templateName)->where('status', TemplateStatus::Approved->value)->where('language', $fallbackLocale)->first();

            if ($template === null) {
                return ['how' => 'skipped', 'wamid' => null, 'reason' => 'template_not_approved'];
            }

            $values = array_map(fn (string $v): string => MessageTemplate::cleanValue($v), $templateValues);
            $wamid = $this->graph->sendMessage($account->phone_number_id, (string) $account->token, $to, [
                'type' => 'template',
                'template' => [
                    'name' => $template->name,
                    'language' => ['code' => $template->language],
                    'components' => [
                        ['type' => 'body', 'parameters' => array_map(fn (string $v): array => ['type' => 'text', 'text' => $v], $values)],
                        ...array_map(fn (int $i, string $payload): array => ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $i, 'parameters' => [['type' => 'payload', 'payload' => $payload]]], array_keys($buttonPayloads), $buttonPayloads),
                    ],
                ],
            ]);
            $this->ledger->record('whatsapp.template.'.strtolower($template->category), 1, 'staff_alert', $usageSourceId);

            return ['how' => 'template', 'wamid' => $wamid, 'reason' => null];
        } catch (GraphException $exception) {
            return ['how' => 'failed', 'wamid' => null, 'reason' => mb_substr($exception->getMessage(), 0, 190)];
        }
    }
}
