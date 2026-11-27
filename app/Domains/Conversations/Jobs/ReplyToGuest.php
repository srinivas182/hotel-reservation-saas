<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Jobs;

use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Domains\Conversations\ConversationHandover;
use App\Domains\Conversations\ConversationMode;
use App\Domains\Conversations\EmergencyWords;
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

    /**
     * Upper bound only: retryUntil() decides when the job gives up, so a
     * reply waiting for another one in the same conversation is not dropped.
     */
    public int $tries = 30;

    /** @var list<int> seconds between attempts */
    public array $backoff = [5, 20];

    /** Seconds to wait when another reply in the conversation is being prepared. */
    public const BUSY_RETRY_SECONDS = 5;

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

    /** 0.38.0: 60 (was 30): a long booking conversation must never reach it. */
    public const MAX_REPLIES_PER_HOUR = 60;

    public const STAFF_QUIET_MINUTES = 10;

    /**
     * Hand-overs during which the assistant keeps answering new questions
     * until a colleague takes over or replies (never a staff take-over).
     */
    public const RESUMABLE = ['guest_asked_for_person', 'reply_limit'];

    /**
     * 0.38.0: a job that waits for another reply in the same conversation is
     * released again and again; it is limited in time, not in attempts, so a
     * message sent while the assistant is still answering is never dropped.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

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
            $this->release(self::BUSY_RETRY_SECONDS);

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

        if ($conversation === null) {
            return;
        }

        if (! $this->assistantMayAnswer($conversation)) {
            // 0.38.0: waiting for staff is never silence for good: once the
            // wait is over, the assistant comes back to this message.
            $retryAt = $this->retryAt($conversation);
            // A sync queue would run the delayed job at once, again and again.
            if ($retryAt !== null && ! $this->job instanceof \Illuminate\Queue\Jobs\SyncJob) {
                self::dispatch($this->conversationId, $this->triggerMessageId)->delay($retryAt);
            }

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
            if ($this->waitingForStaff($conversation)) {
                // Already with staff for this reason: no second hand-over message.
                $this->markAnswered($conversation, $latest);

                return;
            }

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

        $waiting = $this->waitingForStaff($conversation);

        // 0.37.4: "typing…" in the guest's chat while the answer is prepared.
        $typing = app(TypingIndicator::class);
        $typing->start($latest, react: true);

        try {
            $result = $engine->respond(
                $this->turns($conversation),
                $conversation->id,
                $conversation->contact?->guest?->name(),
                $this->situation($context, $conversation, $unanswered, $waiting),
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

        // 0.39.3: an emergency is always flagged for staff (and alerts them),
        // whatever the assistant concluded. The assistant's reply still goes out.
        if (! str_starts_with((string) $result->handoverReason, 'emergency') && $result->intent !== 'emergency') {
            $words = implode(' ', array_map(fn (Message $m): string => (string) ($m->type === 'audio' ? $m->transcript : $m->body), $unanswered));
            if (EmergencyWords::matches($words)) {
                $handover->flag($conversation, 'emergency: '.mb_substr(trim($words), 0, 180));
            }
        }

        if ($result->handover) {
            // The guest asking for a person (the AI saw it) is a real take-over.
            $reason = $result->intent === 'human_request' ? 'guest_asked_for_person' : (string) $result->handoverReason;

            // Already waiting for a colleague: the AI's answer goes out, with
            // no second hand-over (and no second "a colleague will reply").
            if ($waiting && in_array($reason, self::TAKE_OVER, true)) {
                $this->send($conversation, $result->reply, $sender, $handover);
                $this->markAnswered($conversation, $latest);

                return;
            }

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

        $this->send($conversation, $reply, $sender, $handover);
        $this->markAnswered($conversation, $latest);
    }

    /**
     * 0.39.2: one copy only: the same text the assistant sent to this guest
     * in the last 10 minutes is not sent again (testers saw the hand-over
     * message two or three times).
     */
    private function send(Conversation $conversation, string $text, MessageSender $sender, ConversationHandover $handover, bool $quiet = false): void
    {
        $sentAlready = $conversation->messages()->where('source', MessageSource::Assistant->value)
            ->where('sent_at', '>', now()->subMinutes(10))
            ->pluck('body')
            ->contains(fn (?string $body): bool => trim((string) $body) === trim($text));

        if ($sentAlready) {
            return;
        }

        try {
            $sender->sendText($conversation, $text, null, MessageSource::Assistant);
        } catch (CannotSend $exception) {
            Log::warning('Assistant reply not sent', ['conversation' => $conversation->id, 'reason' => $exception->getMessage()]);
            if (! $quiet) {
                $handover->toStaff($conversation, 'send_failed');
            }
        }
    }

    private function assistantMayAnswer(Conversation $conversation): bool
    {
        if (HotelAssistantSettings::current()->mode === AssistantMode::Off) {
            return false;
        }

        // 0.39.2: a guest waiting for a colleague is never left in silence.
        // Until someone takes the conversation or replies, the assistant
        // answers the guest's new questions at once; the conversation stays
        // with staff (and flagged) so a colleague still picks it up.
        if ($conversation->mode !== ConversationMode::Assistant && ! $this->waitingForStaff($conversation)) {
            return false;
        }

        if ($conversation->staff_replied_at !== null && $conversation->staff_replied_at->gt(now()->subMinutes(self::STAFF_QUIET_MINUTES))) {
            return false;
        }

        $account = WhatsAppAccount::query()->first();

        return $account !== null && $account->status->canSend();
    }

    /**
     * When the assistant may answer again, if the only obstacle is a wait:
     * a colleague's recent reply, or a hand-over nobody has picked up yet.
     */
    private function retryAt(Conversation $conversation): ?\Carbon\CarbonImmutable
    {
        if (HotelAssistantSettings::current()->mode === AssistantMode::Off) {
            return null;
        }

        if ($conversation->mode === ConversationMode::Assistant && $conversation->staff_replied_at !== null) {
            $at = $conversation->staff_replied_at->addMinutes(self::STAFF_QUIET_MINUTES)->addSeconds(5);

            return $at->isFuture() ? $at : null;
        }

        return null;
    }

    /**
     * 0.39.2: handed to staff by the assistant (the guest asked for a person,
     * or the reply limit), and no colleague has taken it or replied since.
     */
    private function waitingForStaff(Conversation $conversation): bool
    {
        return $conversation->mode === ConversationMode::Staff
            && in_array($conversation->handover_reason, self::RESUMABLE, true)
            && $conversation->assigned_to === null
            && $conversation->handed_over_at !== null
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

    /**
     * @param  list<Message>  $unanswered
     */
    private function situation(TenantContext $context, Conversation $conversation, array $unanswered, bool $waiting): string
    {
        $hours = StaffedHours::forCurrentHotel($context->hotel());
        $lines = [];

        if ($hours->isOpen()) {
            $lines[] = 'Reception is staffed now: booking requests are usually answered quickly.';
        } else {
            $opening = $hours->nextOpening();
            $lines[] = $opening === null
                ? 'Reception is not staffed now. Still take booking requests (rule 3): the rooms are held until staff confirm.'
                : 'Reception is closed now. Still take booking requests (rule 3): the rooms are held, and staff reply and confirm bookings from '.$this->when($opening, $context).'. Tell the guest this when relevant.';
        }

        if ($waiting) {
            $lines[] = 'A colleague has already been asked to reply to this guest and has not yet. Answer the guest\'s new questions now; do not hand over again for the same request (say a colleague will reply soon only if the guest asks).';
        }

        if (count($unanswered) > 1) {
            $lines[] = 'The guest sent '.count($unanswered).' messages since your last reply: answer every question in them (rule 19).';
        }

        $language = $this->guestLanguage($conversation, $unanswered);
        if ($language !== null) {
            $lines[] = 'LANGUAGE: the guest is writing in "'.$language.'": reply in that language (rule 20).';
        }

        return implode("\n", $lines);
    }

    /**
     * "today 08:00" or "tomorrow 08:00" or "Monday 08:00", hotel time.
     */
    private function when(\Carbon\CarbonInterface $opening, TenantContext $context): string
    {
        $timezone = $context->hotel()->timezone ?: config('app.timezone');
        $local = $opening->copy()->setTimezone($timezone);
        $today = now($timezone);

        return match (true) {
            $local->isSameDay($today) => 'today at '.$local->format('H:i').' (hotel time)',
            $local->isSameDay($today->copy()->addDay()) => 'tomorrow at '.$local->format('H:i').' (hotel time)',
            default => $local->format('l').' at '.$local->format('H:i').' (hotel time)',
        };
    }

    /**
     * 0.39.2: the language of the guest's latest words (text, or a voice
     * note), so a photo or a sticker after English is answered in English.
     *
     * @param  list<Message>  $unanswered
     */
    private function guestLanguage(Conversation $conversation, array $unanswered): ?string
    {
        foreach (array_reverse($unanswered) as $message) {
            if ($message->language !== null) {
                return $message->language;
            }
        }

        return $conversation->messages()->where('direction', 'in')->whereNotNull('language')
            ->where('sent_at', '>', now()->subHours(Conversation::WINDOW_HOURS))
            ->orderByDesc('sent_at')->orderByDesc('id')->value('language');
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
        $locale = $conversation->contact->guest->preferred_locale ?? app(TenantContext::class)->hotel()->default_locale;

        // 0.39.2: asking again while already waiting: a short reminder, not
        // the hand-over message a second time.
        if ($takeOver && $this->waitingForStaff($conversation) && $conversation->handover_reason === $reason) {
            if (HotelAssistantSettings::current()->effectiveMode() !== AssistantMode::Supervised) {
                $this->send($conversation, __('I have already passed your request to reception: a colleague will reply as soon as possible. Meanwhile I can help with anything else.', locale: $locale), $sender, $handover, quiet: true);
            }
            $this->markAnswered($conversation, $latest);

            return;
        }

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

        // A language the hotel does not use: the hotel's own message, in its language.
        $text = $message !== null && ! str_starts_with($reason, 'language:') ? $message : match (true) {
            $takeOver || str_starts_with($reason, 'language:') => app(AssistantEngine::class)->handoverMessage(HotelAssistantSettings::current(), $locale),
            // 0.39.3: ask the guest to try again; staff can still listen to it.
            $reason === 'voice_note_not_understood' => __('Sorry, I could not hear your voice note. Could you send it again, or write your question?', locale: $locale),
            default => __('Let me check this point with our team: they will get back to you. Meanwhile I can still help you with prices, availability and your booking.', locale: $locale),
        };

        // Staff are already alerted through the conversation.
        $this->send($conversation, $text, $sender, $handover, quiet: true);
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
