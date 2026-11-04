<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Jobs;

use App\Domains\Ai\Exceptions\AiUnavailable;
use App\Domains\Ai\Gateway\AiGateway;
use App\Domains\Assistant\LanguageDetector;
use App\Domains\Conversations\Models\Message;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Transcribes a guest's voice note after it is downloaded. A failure is
 * recorded on the message and never blocks the conversation.
 */
final class TranscribeMessage implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $messageId) {}

    public function handle(AiGateway $gateway, LanguageDetector $detector): void
    {
        $message = Message::query()->find($this->messageId);
        $path = $message?->media['path'] ?? null;

        if ($message === null || $message->type !== 'audio' || ! is_string($path) || $message->transcribed_at !== null) {
            return;
        }

        try {
            $result = $gateway->transcribe($path, $message->conversation_id);
        } catch (AiUnavailable $exception) {
            $message->forceFill(['transcript_error' => mb_substr($exception->getMessage(), 0, 290)])->save();
            ReplyToGuest::schedule($message);

            return;
        }

        $language = $detector->detect($result->text);

        $message->forceFill([
            'transcript' => $result->text,
            'transcript_language' => $language,
            'language' => $language ?? $message->language,
            'transcribed_at' => now(),
            'transcript_error' => $result->text === '' ? __('Nothing could be heard in this voice note.') : null,
        ])->save();

        ReplyToGuest::schedule($message);
    }
}
