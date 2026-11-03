<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Templates;

use App\Domains\Audit\Audit;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Graph\GraphClient;
use App\Domains\WhatsApp\Graph\GraphException;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Models\User;
use InvalidArgumentException;

/**
 * Creates templates from the starter library, submits them to Meta and
 * deletes them (current hotel).
 */
final class TemplateService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GraphClient $graph,
    ) {}

    public function createFromStarter(string $key, string $language): MessageTemplate
    {
        $starter = StarterTemplates::all()[$key] ?? throw new InvalidArgumentException('Unknown starter template.');
        $body = $starter['bodies'][$language] ?? throw new InvalidArgumentException('This template is not available in that language.');

        return MessageTemplate::query()->firstOrCreate(
            ['name' => $starter['name'], 'language' => $language],
            [
                'starter_key' => $key,
                'category' => $starter['category'],
                'body' => $body,
                'examples' => $starter['examples'],
                'status' => TemplateStatus::Draft,
            ],
        );
    }

    /**
     * Submit (or resubmit after a rejection) to Meta. Returns false with
     * the reason saved on the template when Meta refuses it.
     */
    public function submit(MessageTemplate $template, ?User $by = null): bool
    {
        $account = $this->account();

        $components = [[
            'type' => 'BODY',
            'text' => $template->body,
            ...($template->placeholders() > 0 ? ['example' => ['body_text' => [$template->examples]]] : []),
        ]];

        try {
            if ($template->meta_template_id !== null) {
                $result = $this->graph->editTemplate($template->meta_template_id, (string) $account->token, $components);
            } else {
                $result = $this->graph->createTemplate($account->waba_id, (string) $account->token, $template->name, $template->language, $template->category, $components);
            }
        } catch (GraphException $exception) {
            $template->forceFill([
                'status' => TemplateStatus::Rejected,
                'rejection_reason' => mb_substr($exception->getMessage(), 0, 490),
            ])->save();

            return false;
        }

        $template->forceFill([
            'meta_template_id' => $result['id'] ?? $template->meta_template_id,
            'status' => TemplateStatus::fromMeta((string) ($result['status'] ?? 'PENDING')) ?? TemplateStatus::Pending,
            'category' => is_string($result['category'] ?? null) ? $result['category'] : $template->category,
            'rejection_reason' => null,
            'submitted_at' => now(),
        ])->save();

        Audit::record('whatsapp.template_submitted', 'WhatsApp template submitted', subject: $this->context->hotel(), causer: $by, properties: [
            'name' => $template->name,
            'language' => $template->language,
        ]);

        return true;
    }

    public function delete(MessageTemplate $template, ?User $by = null): void
    {
        if ($template->meta_template_id !== null) {
            $account = $this->account();
            $this->graph->deleteTemplate($account->waba_id, (string) $account->token, $template->name, $template->meta_template_id);
        }

        $template->delete();

        Audit::record('whatsapp.template_deleted', 'WhatsApp template deleted', subject: $this->context->hotel(), causer: $by, properties: [
            'name' => $template->name,
            'language' => $template->language,
        ]);
    }

    private function account(): WhatsAppAccount
    {
        $account = WhatsAppAccount::query()->first();

        if ($account === null || $account->token === null || ! $account->status->canSend()) {
            throw new GraphException(__('WhatsApp is not connected. Ask the owner to reconnect it.'));
        }

        return $account;
    }
}
