<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Webhooks;

use App\Domains\Assistant\LanguageDetector;
use App\Domains\Conversations\ConversationDirectory;
use App\Domains\Conversations\Jobs\DownloadMedia;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\MessageStatus;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Webhooks\WebhookHandler;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Incoming guest messages and delivery statuses ("messages" webhooks),
 * plus copies of messages staff send from the WhatsApp Business app
 * ("smb_message_echoes") for numbers shared with the app.
 */
final class MessagesHandler implements WebhookHandler
{
    private const MEDIA_TYPES = ['audio', 'image', 'document', 'video', 'sticker'];

    public function __construct(
        private readonly ConversationDirectory $directory,
        private readonly LanguageDetector $detector,
    ) {}

    public function kinds(): array
    {
        return ['messages', 'smb_message_echoes'];
    }

    public function handle(WebhookEvent $event, WhatsAppAccount $account): void
    {
        $value = $event->payload;

        $names = [];
        foreach ((array) ($value['contacts'] ?? []) as $contact) {
            if (is_array($contact) && isset($contact['wa_id'])) {
                $names[(string) $contact['wa_id']] = is_array($contact['profile'] ?? null) ? (string) ($contact['profile']['name'] ?? '') : '';
            }
        }

        foreach ((array) ($value['messages'] ?? []) as $message) {
            if (is_array($message)) {
                $this->inbound($message, $names);
            }
        }

        foreach ((array) ($value['message_echoes'] ?? []) as $echo) {
            if (is_array($echo)) {
                $this->echo($echo);
            }
        }

        foreach ((array) ($value['statuses'] ?? []) as $status) {
            if (is_array($status)) {
                $this->status($status);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $names
     */
    private function inbound(array $data, array $names): void
    {
        $wamid = (string) ($data['id'] ?? '');
        $from = (string) ($data['from'] ?? '');

        if ($wamid === '' || $from === '' || Message::query()->where('wamid', $wamid)->exists()) {
            return;
        }

        $sentAt = $this->time($data['timestamp'] ?? null);
        $conversation = $this->directory->forWaId($from, $names[$from] ?? null);
        [$type, $body, $media] = $this->content($data);

        $message = $this->create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'wamid' => $wamid,
            'type' => $type,
            'body' => $body,
            'media' => $media,
            'status' => MessageStatus::Received,
            'source' => MessageSource::Guest,
            'sent_at' => $sentAt,
            'language' => $type === 'text' ? $this->detector->detect($body) : null,
        ]);

        if ($message === null) {
            return;
        }

        DB::table('conversations')->where('id', $conversation->id)->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'updated_at' => now(),
        ]);
        $this->touch($conversation, $sentAt, inbound: true);
        $conversation->contact()->update(['last_inbound_at' => $sentAt]);

        $guest = $conversation->contact?->guest;
        if ($guest !== null && $guest->preferred_locale === null && $message->language !== null) {
            $guest->forceFill(['preferred_locale' => $message->language])->save();
        }

        if ($media !== null && isset($media['media_id'])) {
            DownloadMedia::dispatch($message->id);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function echo(array $data): void
    {
        $wamid = (string) ($data['id'] ?? '');
        $to = (string) ($data['to'] ?? '');

        if ($wamid === '' || $to === '' || Message::query()->where('wamid', $wamid)->exists()) {
            return;
        }

        $sentAt = $this->time($data['timestamp'] ?? null);
        $conversation = $this->directory->forWaId($to);
        [$type, $body, $media] = $this->content($data);

        $this->create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'wamid' => $wamid,
            'type' => $type,
            'body' => $body,
            'media' => $media,
            'status' => MessageStatus::Sent,
            'status_at' => $sentAt,
            'source' => MessageSource::BusinessApp,
            'sent_at' => $sentAt,
        ]);

        $this->touch($conversation, $sentAt, inbound: false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function status(array $data): void
    {
        $next = MessageStatus::tryFrom((string) ($data['status'] ?? ''));
        $message = Message::query()->where('wamid', (string) ($data['id'] ?? ''))->first();

        if ($next === null || $message === null || ! $message->status->canMoveTo($next)) {
            return;
        }

        $error = is_array($data['errors'][0] ?? null) ? $data['errors'][0] : null;

        $message->forceFill([
            'status' => $next,
            'status_at' => $this->time($data['timestamp'] ?? null),
            'error_code' => $error === null ? $message->error_code : (int) ($error['code'] ?? 0),
            'error_text' => $error === null ? $message->error_text : mb_substr((string) ($error['title'] ?? $error['message'] ?? ''), 0, 490),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string|null, 2: array<string, mixed>|null}
     */
    private function content(array $data): array
    {
        $type = (string) ($data['type'] ?? 'unknown');
        $part = is_array($data[$type] ?? null) ? $data[$type] : [];

        return match (true) {
            $type === 'text' => ['text', (string) ($part['body'] ?? ''), null],
            in_array($type, self::MEDIA_TYPES, true) => [$type, isset($part['caption']) ? (string) $part['caption'] : null, [
                'media_id' => (string) ($part['id'] ?? ''),
                'mime_type' => (string) ($part['mime_type'] ?? ''),
                'filename' => isset($part['filename']) ? (string) $part['filename'] : null,
                'voice' => (bool) ($part['voice'] ?? false),
            ]],
            $type === 'location' => ['location', trim(((string) ($part['name'] ?? '')).' '.((string) ($part['address'] ?? ''))) ?: null, [
                'latitude' => (float) ($part['latitude'] ?? 0),
                'longitude' => (float) ($part['longitude'] ?? 0),
            ]],
            $type === 'interactive' => ['interactive', (string) ($part['button_reply']['title'] ?? $part['list_reply']['title'] ?? ''), null],
            $type === 'button' => ['button', (string) ($part['text'] ?? ''), null],
            default => [$type, null, null],
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(array $attributes): ?Message
    {
        try {
            return Message::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            // A parallel delivery of the same message got there first.
            return null;
        }
    }

    private function touch(Conversation $conversation, CarbonImmutable $at, bool $inbound): void
    {
        $conversation->refresh();
        $changes = [];

        if ($conversation->last_message_at === null || $at->gt($conversation->last_message_at)) {
            $changes['last_message_at'] = $at;
        }

        if ($inbound && ($conversation->last_inbound_at === null || $at->gt($conversation->last_inbound_at))) {
            $changes['last_inbound_at'] = $at;
        }

        if ($changes !== []) {
            $conversation->forceFill([...$changes, 'status' => 'open'])->save();
        }
    }

    private function time(mixed $timestamp): CarbonImmutable
    {
        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestamp((int) $timestamp) : CarbonImmutable::now();
    }
}
