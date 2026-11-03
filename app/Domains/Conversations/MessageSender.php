<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\Jobs\SendMessage;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
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

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id);

        return $message;
    }
}
