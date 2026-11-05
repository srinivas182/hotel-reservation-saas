<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\Jobs\SendMessage;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Queues outgoing messages. Each message gets an idempotency key, so a
 * retried job never sends it twice.
 */
final class MessageSender
{
    public const MAX_TEXT = 4096;

    public function sendText(Conversation $conversation, string $text, ?User $by = null, MessageSource $source = MessageSource::Staff): Message
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            throw new CannotSend(__('Write a message of up to :max characters.', ['max' => self::MAX_TEXT]));
        }

        $account = WhatsAppAccount::query()->first();

        if ($account === null || ! $account->status->canSend()) {
            throw new CannotSend(__('WhatsApp is not connected. Ask the owner to reconnect it.'));
        }

        if (! $conversation->windowOpen()) {
            throw new CannotSend(__('More than 24 hours have passed since the guest’s last message. Send an approved template instead.'));
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => $text,
            'status' => MessageStatus::Queued,
            'sent_by' => $by?->getKey(),
            'source' => $source,
            'sent_at' => now(),
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            ...($source === MessageSource::Staff ? ['staff_replied_at' => now(), 'needs_attention' => false, 'waiting_since' => null] : []),
        ])->save();

        SendMessage::dispatch($message->id);

        return $message;
    }

    /**
     * Send an approved template. Allowed at any time, including outside the
     * 24-hour window.
     *
     * @param  list<string>  $values  one value per placeholder
     */
    public function sendTemplate(Conversation $conversation, MessageTemplate $template, array $values, ?User $by = null, MessageSource $source = MessageSource::Staff): Message
    {
        if ($template->status !== TemplateStatus::Approved) {
            throw new CannotSend(__('This template is not approved by Meta yet.'));
        }

        $values = array_map(fn (string $value): string => trim($value), $values);

        if (count($values) !== $template->placeholders() || in_array('', $values, true)) {
            throw new CannotSend(__('Fill in every value of the template.'));
        }

        $account = WhatsAppAccount::query()->first();

        if ($account === null || ! $account->status->canSend()) {
            throw new CannotSend(__('WhatsApp is not connected. Ask the owner to reconnect it.'));
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'client_id' => (string) Str::uuid(),
            'type' => 'template',
            'body' => $template->render($values),
            'template_name' => $template->name,
            'template_language' => $template->language,
            'media' => [
                'category' => strtolower($template->category),
                'components' => $values === [] ? [] : [[
                    'type' => 'body',
                    'parameters' => array_map(fn (string $value): array => ['type' => 'text', 'text' => $value], $values),
                ]],
            ],
            'status' => MessageStatus::Queued,
            'sent_by' => $by?->getKey(),
            'source' => $source,
            'sent_at' => now(),
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            ...($source === MessageSource::Staff ? ['staff_replied_at' => now(), 'needs_attention' => false, 'waiting_since' => null] : []),
        ])->save();

        SendMessage::dispatch($message->id);

        return $message;
    }

    /**
     * Supervised mode: keep the assistant's reply as a draft for staff.
     */
    public function draft(Conversation $conversation, string $text): Message
    {
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'text',
            'body' => $text,
            'status' => MessageStatus::Draft,
            'source' => MessageSource::Assistant,
            'sent_at' => now(),
        ]);

        // The guest is waiting until staff send it.
        if ($conversation->waiting_since === null) {
            $conversation->forceFill(['waiting_since' => now()])->save();
        }

        return $message;
    }

    /**
     * Send a draft as written or after staff edited it.
     */
    public function sendDraft(Message $draft, ?string $editedText, User $by): Message
    {
        if ($draft->status !== MessageStatus::Draft) {
            throw new CannotSend(__('This draft has already been dealt with.'));
        }

        $conversation = $draft->conversation()->firstOrFail();
        $text = trim($editedText ?? (string) $draft->body);
        $edited = $editedText !== null && trim($editedText) !== trim((string) $draft->body);

        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            throw new CannotSend(__('Write a message of up to :max characters.', ['max' => self::MAX_TEXT]));
        }

        if (! $conversation->windowOpen()) {
            throw new CannotSend(__('More than 24 hours have passed since the guest’s last message. Send an approved template instead.'));
        }

        $account = WhatsAppAccount::query()->first();

        if ($account === null || ! $account->status->canSend()) {
            throw new CannotSend(__('WhatsApp is not connected. Ask the owner to reconnect it.'));
        }

        $draft->forceFill([
            'body' => $text,
            'client_id' => (string) Str::uuid(),
            'status' => MessageStatus::Queued,
            'sent_by' => $by->getKey(),
            'sent_at' => now(),
            'draft_outcome' => $edited ? 'edited' : 'sent_unchanged',
        ])->save();

        $this->afterDraftHandled($conversation);
        SendMessage::dispatch($draft->id);

        return $draft;
    }

    public function discardDraft(Message $draft): void
    {
        if ($draft->status !== MessageStatus::Draft) {
            return;
        }

        $draft->forceFill(['status' => MessageStatus::Discarded, 'draft_outcome' => 'discarded'])->save();
        $this->afterDraftHandled($draft->conversation()->firstOrFail());
    }

    private function afterDraftHandled(Conversation $conversation): void
    {
        $pending = $conversation->messages()->where('status', MessageStatus::Draft->value)->exists();

        $conversation->forceFill([
            'last_message_at' => now(),
            ...($pending ? [] : ['waiting_since' => null]),
        ])->save();
    }
}
