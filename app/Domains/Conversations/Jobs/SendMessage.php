<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Jobs;

use App\Domains\Conversations\MessageStatus;
use App\Domains\Conversations\Models\Message;
use App\Domains\Messaging\Usage\UsageLedger;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\WhatsApp\Graph\GraphClient;
use App\Domains\WhatsApp\Graph\GraphException;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\WhatsAppConnector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a queued message through the Cloud API. A message that already has
 * Meta's ID is never sent again. Errors Meta reports about the message
 * itself fail it at once with a plain explanation; network and server
 * errors are retried.
 */
final class SendMessage implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    /** Seconds before a stuck attempt is stopped. */
    public int $timeout = 60;

    public int $tries = 4;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $messageId) {}

    public function handle(GraphClient $graph, WhatsAppConnector $connector, UsageLedger $ledger): void
    {
        $message = Message::query()->with('conversation.contact')->find($this->messageId);

        if ($message === null || $message->wamid !== null || $message->status !== MessageStatus::Queued) {
            return;
        }

        $account = WhatsAppAccount::query()->first();
        $to = $message->conversation?->contact?->wa_id;

        if ($account === null || $account->token === null || ! $account->status->canSend() || $to === null) {
            $this->fail($message, 0, __('WhatsApp is not connected. Ask the owner to reconnect it.'));

            return;
        }

        try {
            $wamid = $graph->sendMessage($account->phone_number_id, $account->token, $to, $this->payload($message));
        } catch (GraphException $exception) {
            if ($exception->isInvalidToken()) {
                $connector->markReconnectRequired($account, $exception->getMessage());
                $this->fail($message, $exception->metaCode, self::explain($exception->metaCode, $exception->getMessage()));

                return;
            }

            if ($exception->httpStatus >= 500 || $exception->httpStatus === 0 || $exception->httpStatus === 429) {
                throw $exception;
            }

            $this->fail($message, $exception->metaCode, self::explain($exception->metaCode, $exception->getMessage()));

            return;
        }

        $message->forceFill(['wamid' => $wamid, 'status' => MessageStatus::Accepted, 'status_at' => now()])->save();

        // Counted once per message, whatever happens to retries.
        $ledger->record(
            $message->type === 'template' ? 'whatsapp.template.'.($message->media['category'] ?? 'utility') : 'whatsapp.service',
            1,
            'message',
            $message->id,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message !== null && $message->status === MessageStatus::Queued) {
            $this->fail($message, 0, __('WhatsApp could not be reached. Try again in a few minutes.'));
        }
    }

    /**
     * Plain-language explanations for Meta's most common send errors.
     */
    public static function explain(int $code, string $fallback): string
    {
        return match ($code) {
            131047 => __('More than 24 hours have passed since the guest’s last message. Send an approved template instead.'),
            131026 => __('This number cannot receive WhatsApp messages.'),
            131049 => __('Meta did not deliver this message to keep engagement healthy. Try later.'),
            131056 => __('Too many messages to this guest in a short time. Wait a moment.'),
            132000, 132001, 132005, 132007, 132012 => __('The template is missing, not approved or its details do not match.'),
            190 => __('WhatsApp needs to be reconnected by the owner.'),
            131031 => __('The WhatsApp account is locked by Meta.'),
            default => $fallback,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Message $message): array
    {
        return match ($message->type) {
            'template' => [
                'type' => 'template',
                'template' => [
                    'name' => $message->template_name,
                    'language' => ['code' => $message->template_language],
                    ...(is_array($message->media['components'] ?? null) ? ['components' => $message->media['components']] : []),
                ],
            ],
            default => [
                'type' => 'text',
                'text' => ['body' => (string) $message->body, 'preview_url' => false],
            ],
        };
    }

    private function fail(Message $message, int $code, string $text): void
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'status_at' => now(),
            'error_code' => $code === 0 ? null : $code,
            'error_text' => mb_substr($text, 0, 490),
        ])->save();
    }
}
