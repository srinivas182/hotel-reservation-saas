<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Jobs;

use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Domains\Conversations\ConversationHandover;
use App\Domains\Conversations\ConversationMode;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Conversations\TypingIndicator;
use App\Domains\Hotel\StaffedHours;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Answers a guest with the assistant. Dispatched 8 seconds after each guest
 * message (or once a voice note is transcribed); only the job for the
 * newest message answers, so a burst of messages gets one reply. A lock
 * per conversation means one reply is prepared at a time, and every guest
 * message is answered exactly once.
 */
final class ReplyToGuest implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    /** @var list<int> seconds between attempts */
    public array $backoff = [5, 20];

    /** Seconds before a stuck attempt is stopped. */
    public int $timeout = 120;

    public const DELAY_SECONDS = 2;

    /**
     * 0.36.5: the only reasons that take the conversation away from the
     * assistant: the guest asked for a person, or the daily reply limit
     * (abuse). Every other reason flags the conversation and alerts staff,
     * while the assistant keeps answering and guiding the guest to book.
     */
    public const TAKE_OVER = ['guest_asked_for_person', 'reply_limit'];

    public const MAX_REPLIES_PER_HOUR = 30;

    public const STAFF_QUIET_MINUTES = 10;

    /**
     * 0.37.5: minutes after which a conversation the assistant passed to
     * staff, and no one answered, goes back to the assistant.
     */
    public const RESUME_MINUTES = 30;

    /** Hand-overs the assistant may take back (never a staff take-over). */
    public const RESUMABLE = ['guest_asked_for_person'];

    public int $tries = 3;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $triggerMessageId,
    ) {}

    public static function schedule(Message $message): void
    {
        self::dispatch($message->conversation_id, $message->id)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function handle(AssistantEngine $engine, MessageSender $sender, ConversationHandover $handover, TenantContext $context): void
    {
        $lock = Cache::lock("assistant-reply:{$this->conversationId}", 180);

        if (! $lock->get()) {
            // Another reply is being prepared; answer what is left afterwards.
            $this->release(self::DELAY_SECONDS);

            return;
        }

        try {
            $this->reply($engine, $sender, $handover, $context);
        } finally {
            $lock->release();
        }
    }

    private function reply(AssistantEngine $engine, MessageSender $sender, ConversationHandover $handover, TenantContext $context): void
    {
        $conversation = Conversation::query()->with('contact.guest')->find($this->conversationId);

        if ($conversation === null || ! $this->assistantMayAnswer($conversation)) {
            return;
        }

        $unanswered = $this->unanswered($conversation);
        $latest = $unanswered === [] ? null : end($unanswered);

        // Only the job for the newest guest message answers.
        if ($latest === null || $latest->id !== $this->triggerMessageId) {
            return;
        }

        // Late webhook deliveries: never answer messages older than 24 hours.
        if ($latest->sent_at->lt(now()->subHours(Conversation::WINDOW_HOURS))) {
            $this->markAnswered($conversation, $latest);

            return;
        }

        foreach ($unanswered as $message) {
            if ($message->type === 'audio' && trim((string) $message->transcript) === '') {
                if ($message->transcript_error !== null || isset($message->media['error'])) {
                    $this->handOver($conversation, $latest, 'voice_note_not_understood', $sender, $handover);
                }

                // Otherwise the transcription job schedules the reply.
                return;
            }
        }

        if ($this->tooManyReplies($conversation)) {
            $this->handOver($conversation, $latest, 'reply_limit', $sender, $handover);

            return;
        }

        if ($this->repeating($conversation)) {
            $this->handOver($conversation, $latest, 'repeated_replies', $sender, $handover);

            return;
        }

        if ($this->asksForPerson($unanswered)) {
            $this->handOver($conversation, $latest, 'guest_asked_for_person', $sender, $handover);

            return;
        }

        // 0.37.4: "typing…" in the guest's chat while the answer is prepared.
        $typing = app(TypingIndicator::class);
        $typing->start($latest, react: true);

        try {
            $result = $engine->respond(
                $this->turns($conversation),
                $conversation->id,
                $conversation->contact?->guest?->name(),
                $this->situation($context),
            );
        } finally {
            $typing->stop();
        }

        // The guest's language follows what they write now (0.36.1): later
        // messages (holding messages, confirmations, payment page) use it.
        if (! str_starts_with((string) $result->handoverReason, 'language:')) {
            $this->rememberLanguage($conversation, $result->language);
        }

        // Nothing new from the guest to answer (a reaction, an empty message):
        // stay silent rather than hand the guest to staff.
        if ($result->handoverReason === 'nothing_to_answer') {
            $this->markAnswered($conversation, $latest);

            return;
        }

        if ($result->handover) {
            // The guest asking for a person (the AI saw it) is a real take-over.
            $reason = $result->intent === 'human_request' ? 'guest_asked_for_person' : (string) $result->handoverReason;
            // The AI's own reply goes out; when the engine had no usable reply
            // (blocked twice, no information pack, AI unavailable) a neutral one does.
            $engineOnly = $reason === 'guardrail' || $reason === 'no_information_pack' || str_starts_with($reason, 'ai_unavailable');
            $this->handOver($conversation, $latest, $reason, $sender, $handover, $engineOnly ? null : $result->reply);

            return;
        }

        $reply = $this->withDisclosure($conversation, $result->reply, $result->language);

        if (HotelAssistantSettings::current()->effectiveMode() === AssistantMode::Supervised) {
            $sender->draft($conversation, $reply);
            $this->markAnswered($conversation, $latest);

            return;
        }

        try {
            $sender->sendText($conversation, $reply, null, MessageSource::Assistant);
        } catch (CannotSend $exception) {
            Log::warning('Assistant reply not sent', ['conversation' => $conversation->id, 'reason' => $exception->getMessage()]);
            $handover->toStaff($conversation, 'send_failed');
        }

        $this->markAnswered($conversation, $latest);
    }

    private function assistantMayAnswer(Conversation $conversation): bool
    {
        if (HotelAssistantSettings::current()->mode === AssistantMode::Off) {
            return false;
        }

        // 0.37.5: a guest the assistant passed to staff, whom nobody answered,
        // is not left in silence: after RESUME_MINUTES the assistant answers
        // again (the conversation stays flagged for staff).
        if ($this->noStaffCame($conversation)) {
            app(ConversationHandover::class)->resume($conversation);
        }

        if ($conversation->mode !== ConversationMode::Assistant) {
            return false;
        }

        if ($conversation->staff_replied_at !== null && $conversation->staff_replied_at->gt(now()->subMinutes(self::STAFF_QUIET_MINUTES))) {
            return false;
        }

        $account = WhatsAppAccount::query()->first();

        return $account !== null && $account->status->canSend();
    }

    private function noStaffCame(Conversation $conversation): bool
    {
        return $conversation->mode === ConversationMode::Staff
            && in_array($conversation->handover_reason, self::RESUMABLE, true)
            && $conversation->assigned_to === null
            && $conversation->handed_over_at !== null
            && $conversation->handed_over_at->lte(now()->subMinutes(self::RESUME_MINUTES))
            && ($conversation->staff_replied_at === null || $conversation->staff_replied_at->lt($conversation->handed_over_at));
    }

    /**
     * Guest messages after the last one answered, oldest first.
     *
     * @return list<Message>
     */
    private function unanswered(Conversation $conversation): array
    {
        // Reactions (👍) never wait for an answer.
        $query = $conversation->messages()->where('direction', 'in')->where('type', '!=', 'reaction')->orderBy('sent_at')->orderBy('id');

        if ($conversation->last_answered_message_id !== null) {
            $answered = Message::query()->find($conversation->last_answered_message_id);

            if ($answered !== null) {
                $query->where(fn ($q) => $q->where('sent_at', '>', $answered->sent_at)
                    ->orWhere(fn ($same) => $same->where('sent_at', $answered->sent_at)->where('id', '>', $answered->id)));
            }
        }

        return array_values($query->get()->all());
    }

    /**
     * @return list<AssistantTurn>
     */
    private function turns(Conversation $conversation): array
    {
        $messages = $conversation->messages()
            ->where('sent_at', '>=', now()->subHours(Conversation::WINDOW_HOURS))
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->limit(AssistantEngine::HISTORY_LIMIT)
            ->get()
            ->reverse();

        $turns = [];
        foreach ($messages as $message) {
            $text = match ($message->type) {
                'audio' => $message->transcript !== null ? '[Voice note] '.$message->transcript : '[Voice note]',
                'image' => '[The guest sent a photo]'.($message->body ? ' '.$message->body : ''),
                'document' => '[The guest sent a document]',
                'video' => '[The guest sent a video]'.($message->body ? ' '.$message->body : ''),
                'sticker' => '[The guest sent a sticker]',
                'reaction' => '',
                'location' => '[The guest shared a location]'.($message->body ? ' '.$message->body : ''),
                default => (string) $message->body,
            };

            if (trim($text) !== '') {
                $turns[] = new AssistantTurn($message->isInbound() ? 'guest' : 'hotel', $text);
            }
        }

        // Several guest messages in a row are answered together.
        $merged = [];
        foreach ($turns as $turn) {
            $last = end($merged);

            if ($last !== false && $last->role === $turn->role) {
                $merged[array_key_last($merged)] = new AssistantTurn($turn->role, $last->text."\n".$turn->text);
            } else {
                $merged[] = $turn;
            }
        }

        return $merged;
    }

    private function situation(TenantContext $context): string
    {
        $hours = StaffedHours::forCurrentHotel($context->hotel());

        if ($hours->isOpen()) {
            return 'Reception is staffed now: booking requests are usually answered quickly.';
        }

        $opening = $hours->nextOpening();

        return $opening === null
            ? 'Reception is not staffed now. Still take booking requests (rule 3): the rooms are held until staff confirm.'
            : 'Reception is closed now. Still take booking requests (rule 3): the rooms are held, and staff reply and confirm bookings from '.$opening->format('l H:i').' (hotel time). Tell the guest this when relevant.';
    }

    private function tooManyReplies(Conversation $conversation): bool
    {
        return $conversation->messages()
            ->where('source', MessageSource::Assistant->value)
            ->where('sent_at', '>', now()->subHour())
            ->count() >= self::MAX_REPLIES_PER_HOUR;
    }

    private function repeating(Conversation $conversation): bool
    {
        $last = $conversation->messages()
            ->where('source', MessageSource::Assistant->value)
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Message $m): string => mb_strtolower(preg_replace('/\W+/u', '', (string) $m->body) ?? ''));

        return $last->count() === 5 && $last->unique()->count() === 1;
    }

    private function handOver(Conversation $conversation, Message $latest, string $reason, MessageSender $sender, ConversationHandover $handover, ?string $message = null): void
    {
        $takeOver = in_array($reason, self::TAKE_OVER, true);
        $takeOver ? $handover->toStaff($conversation, $reason) : $handover->flag($conversation, $reason);

        // In supervised mode nothing is sent to guests without staff approval;
        // a flagged reply waits as a draft like any other.
        if (HotelAssistantSettings::current()->effectiveMode() === AssistantMode::Supervised) {
            if (! $takeOver && $message !== null) {
                $sender->draft($conversation, $message);
            }
            $this->markAnswered($conversation, $latest);

            return;
        }

        $locale = $conversation->contact->guest->preferred_locale ?? app(TenantContext::class)->hotel()->default_locale;
        // A language the hotel does not use: the hotel's own message, in its language.
        $text = $message !== null && ! str_starts_with($reason, 'language:') ? $message : match (true) {
            $takeOver || str_starts_with($reason, 'language:') => app(AssistantEngine::class)->handoverMessage(HotelAssistantSettings::current(), $locale),
            default => __('Let me check this point with our team: they will get back to you. Meanwhile I can still help you with prices, availability and your booking.', locale: $locale),
        };

        try {
            $sender->sendText($conversation, $text, null, MessageSource::Assistant);
        } catch (CannotSend) {
            // Staff are already alerted through the conversation.
        }

        $this->markAnswered($conversation, $latest);
    }

    /**
     * A guest who asks for a person with one of the hotel's keywords
     * ("réception", "human"…) always reaches one.
     *
     * 0.37.5: the keyword alone ("Réception ?", "agent please") or with a
     * wish to talk ("je veux parler à la réception", "call me, a person").
     * A keyword in an ordinary sentence ("can you confirm with reception?",
     * "une chambre pour une personne") is left to the assistant, which
     * flags the question for staff and keeps helping.
     *
     * @param  list<Message>  $messages
     */
    private function asksForPerson(array $messages): bool
    {
        $keywords = array_map(fn (string $k): string => self::fold($k), HotelAssistantSettings::current()->humanKeywords());
        $talk = '(?:talk|speak|chat|connect|parler|discuter|joindre|contacter)';
        $call = '(?:call|phone|ring|contact|appel\w*|rappel\w*|contacte\w*|joindre)';

        foreach ($messages as $message) {
            $text = ' '.self::fold((string) ($message->type === 'audio' ? $message->transcript : $message->body)).' ';

            foreach ($keywords as $keyword) {
                if ($keyword === '' || preg_match('/[^a-z0-9]'.preg_quote($keyword, '/').'[^a-z0-9]/', $text) !== 1) {
                    continue;
                }

                $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $k = preg_quote($keyword, '/');
                // "parler à la réception", "talk to a person", "a real person call me"
                if (count($words) <= 3
                    || preg_match('/\\b'.$talk.'\\b\\W+(?:\\w+\\W+){0,3}'.$k.'\\b/', $text) === 1
                    || preg_match('/\\b'.$k.'\\W+(?:\\w+\\W+){0,2}'.$call.'\\b/', $text) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function fold(string $text): string
    {
        return strtolower(\Illuminate\Support\Str::ascii($text));
    }

    /**
     * The first assistant reply in a conversation says it is a virtual
     * assistant, how to reach a person, and where the privacy notice is.
     */
    private function rememberLanguage(Conversation $conversation, string $language): void
    {
        $guest = $conversation->contact?->guest;

        if ($guest !== null && $language !== '' && $guest->preferred_locale !== $language) {
            $guest->forceFill(['preferred_locale' => $language])->save();
        }
    }

    private function withDisclosure(Conversation $conversation, string $reply, string $language): string
    {
        $already = $conversation->messages()->where('source', MessageSource::Assistant->value)->exists();

        if ($already) {
            return $reply;
        }

        $settings = HotelAssistantSettings::current();
        $hotel = app(TenantContext::class)->hotel();
        $keyword = $settings->humanKeywords()[0];
        $line = __('I am the virtual assistant of :hotel. Write « :keyword » to speak to a person.', ['hotel' => $hotel->name, 'keyword' => $keyword], $language);

        if (trim((string) $settings->privacy_url) !== '') {
            $line .= ' '.__('Privacy: :url', ['url' => $settings->privacy_url], $language);
        }

        return $reply."\n\n".$line;
    }

    private function markAnswered(Conversation $conversation, Message $latest): void
    {
        $conversation->forceFill(['last_answered_message_id' => $latest->id])->save();
    }
}
