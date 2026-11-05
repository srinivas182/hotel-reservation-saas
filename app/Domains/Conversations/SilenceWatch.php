<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Audit\Audit;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Hotel\StaffedHours;
use App\Domains\Tenancy\TenantContext;

/**
 * Never leaves a guest in silence: when a conversation is with staff and
 * the guest has waited longer than the hotel's limit, the guest gets one
 * holding message and staff are alerted again.
 */
final class SilenceWatch
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MessageSender $sender,
        private readonly AssistantEngine $engine,
    ) {}

    public function check(): int
    {
        $settings = HotelAssistantSettings::current();
        $hotel = $this->context->hotel();
        $count = 0;

        $waiting = Conversation::query()
            ->with('contact.guest')
            ->where('mode', ConversationMode::Staff->value)
            ->whereNotNull('waiting_since')
            ->where('waiting_since', '<=', now()->subMinutes($settings->silence_minutes))
            ->where(fn ($q) => $q->whereNull('holding_sent_at')->orWhereColumn('holding_sent_at', '<', 'waiting_since'))
            ->get();

        foreach ($waiting as $conversation) {
            $locale = $conversation->contact->guest->preferred_locale ?? $hotel->default_locale;
            $text = $this->engine->handoverMessage($settings, $locale);
            $hours = StaffedHours::forCurrentHotel($hotel);

            if (! $hours->isOpen() && ($opening = $hours->nextOpening()) !== null) {
                /** @var \Carbon\CarbonImmutable $localized */
                $localized = $opening->locale($locale);
                $text .= ' '.__('Reception opens at :time.', ['time' => $localized->isoFormat('dddd HH:mm')], $locale);
            }

            try {
                $this->sender->sendText($conversation, $text, null, MessageSource::System);
            } catch (CannotSend) {
                // Outside the 24-hour window: staff are still alerted below.
            }

            $conversation->forceFill(['holding_sent_at' => now(), 'needs_attention' => true])->save();
            Audit::record('conversations.guest_waiting', 'Guest waiting for a staff reply', subject: $conversation, properties: [
                'waiting_since' => $conversation->waiting_since?->toIso8601String(),
            ]);
            ConversationNeedsStaff::dispatch($conversation->id, 'guest_waiting');
            $count++;
        }

        // Supervised mode: a draft nobody has approved. Staff are alerted;
        // nothing is sent to the guest without approval.
        $drafts = Conversation::query()
            ->where('mode', ConversationMode::Assistant->value)
            ->whereNotNull('waiting_since')
            ->where('waiting_since', '<=', now()->subMinutes($settings->silence_minutes))
            ->where(fn ($q) => $q->whereNull('holding_sent_at')->orWhereColumn('holding_sent_at', '<', 'waiting_since'))
            ->whereHas('messages', fn ($q) => $q->where('status', MessageStatus::Draft->value))
            ->get();

        foreach ($drafts as $conversation) {
            $conversation->forceFill(['holding_sent_at' => now(), 'needs_attention' => true])->save();
            Audit::record('conversations.draft_waiting', 'Assistant draft waiting for approval', subject: $conversation);
            ConversationNeedsStaff::dispatch($conversation->id, 'guest_waiting');
            $count++;
        }

        return $count;
    }
}
