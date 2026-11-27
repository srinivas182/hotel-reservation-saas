<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Jobs;

use App\Domains\Ai\Exceptions\AiUnavailable;
use App\Domains\Ai\Gateway\AiGateway;
use App\Domains\Ai\Models\HotelAssistantSettings;
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

    /** @var list<int> seconds between attempts */
    public array $backoff = [10];

    /** Seconds before a stuck attempt is stopped. */
    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(public readonly string $messageId) {}

    public function handle(AiGateway $gateway, LanguageDetector $detector): void
    {
        $message = Message::query()->find($this->messageId);
        $path = $message?->media['path'] ?? null;

        if ($message === null || $message->type !== 'audio' || ! is_string($path) || $message->transcribed_at !== null) {
            return;
        }

        // Voice notes switched off by the platform: staff listen to them.
        if (! app(\App\Domains\Platform\Features\Features::class)->enabled(\App\Domains\Platform\Features\Feature::VoiceNotes)) {
            $message->forceFill(['transcript_error' => __('Voice notes are not available for the assistant yet.')])->save();
            ReplyToGuest::schedule($message);

            return;
        }

        // 0.37.4: the guest sees "typing…" while their voice note is heard.
        if ($message->conversation?->mode === \App\Domains\Conversations\ConversationMode::Assistant) {
            app(\App\Domains\Conversations\TypingIndicator::class)->start($message);
        }

        try {
            $result = $gateway->transcribe($path, $message->conversation_id);
        } catch (AiUnavailable $exception) {
            $message->forceFill(['transcript_error' => mb_substr($exception->getMessage(), 0, 290)])->save();
            ReplyToGuest::schedule($message);

            return;
        }

        $language = $detector->detect($result->text);

        // 0.39.3: speech models invent a word or two from silence ("სამგულო."
        // from 4 silent seconds, TC125). A very short transcript with no
        // Latin letters, at a hotel answering in Latin-script languages,
        // is treated as nothing heard.
        $words = preg_split('/[^\p{L}\p{N}]+/u', $result->text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $latinHotel = collect(HotelAssistantSettings::current()->languages ?? ['fr', 'en'])->every(fn ($l): bool => in_array($l, ['fr', 'en', 'es', 'pt', 'de', 'it', 'nl', 'sw', 'yo', 'ha', 'wo'], true));
        $text = $latinHotel && count($words) <= 3 && preg_match('/\p{Latin}/u', $result->text) !== 1 ? '' : $result->text;

        $message->forceFill([
            'transcript' => $text === '' ? null : \App\Domains\Conversations\CardNumberMask::mask($text),
            'transcript_language' => $language,
            'language' => $language ?? $message->language,
            'transcribed_at' => now(),
            'transcript_error' => $text === '' ? __('Nothing could be heard in this voice note.') : null,
        ])->save();

        ReplyToGuest::schedule($message);
    }
}
