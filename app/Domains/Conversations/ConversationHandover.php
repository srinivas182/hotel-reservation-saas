<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

use App\Domains\Audit\Audit;
use App\Domains\Conversations\Models\Conversation;
use App\Models\User;

/**
 * Moves a conversation between the assistant and staff.
 */
final class ConversationHandover
{
    public function toStaff(Conversation $conversation, string $reason, ?User $by = null): void
    {
        $conversation->forceFill([
            'mode' => ConversationMode::Staff,
            'needs_attention' => $by === null,
            'handover_reason' => mb_substr($reason, 0, 250),
            'handed_over_at' => now(),
            // Taking over makes the person who clicked the owner.
            ...($by !== null ? ['assigned_to' => $by->getKey(), 'assigned_at' => now()] : []),
        ])->save();

        Audit::record('conversations.handed_to_staff', $by === null ? 'Conversation passed to staff' : 'Staff took over the conversation', subject: $conversation, causer: $by, properties: ['reason' => $reason]);

        if ($by === null) {
            ConversationNeedsStaff::dispatch($conversation->id, 'handover');
        }
    }

    /**
     * 0.36.5: staff should look, but the assistant keeps answering (e.g. a
     * guest asking for a bigger discount: the assistant gives the hotel's
     * offers, staff may decide to offer more). The conversation is flagged
     * and staff alerted; nothing stops the guest from booking meanwhile.
     */
    public function flag(Conversation $conversation, string $reason): void
    {
        $conversation->forceFill([
            'needs_attention' => true,
            'handover_reason' => mb_substr($reason, 0, 250),
        ])->save();

        Audit::record('conversations.flagged_for_staff', 'Conversation flagged for staff; the assistant keeps answering', subject: $conversation, properties: ['reason' => $reason]);
        ConversationNeedsStaff::dispatch($conversation->id, 'handover');
    }

    public function assign(Conversation $conversation, ?User $assignee, User $by): void
    {
        $previous = $conversation->assigned_to;

        $conversation->forceFill([
            'assigned_to' => $assignee?->getKey(),
            'assigned_at' => $assignee === null ? null : now(),
        ])->save();

        Audit::record('conversations.assigned', $assignee === null ? 'Conversation unassigned' : 'Conversation assigned', subject: $conversation, causer: $by, properties: [
            'from' => $previous,
            'to' => $assignee?->getKey(),
            'to_name' => $assignee?->name,
        ]);
    }

    public function toAssistant(Conversation $conversation, ?User $by = null): void
    {
        $latest = $conversation->messages()->where('direction', 'in')->orderByDesc('sent_at')->orderByDesc('id')->value('id');

        $conversation->forceFill([
            'mode' => ConversationMode::Assistant,
            'needs_attention' => false,
            'handover_reason' => null,
            'assigned_to' => null,
            'assigned_at' => null,
            'waiting_since' => null,
            // Messages staff have already dealt with are not answered again.
            'last_answered_message_id' => $latest ?? $conversation->last_answered_message_id,
        ])->save();

        Audit::record('conversations.handed_to_assistant', 'Conversation handed back to the assistant', subject: $conversation, causer: $by);
    }

    /**
     * 0.37.5: the assistant answers again a guest staff did not answer.
     * Unlike toAssistant(), the guest's new messages stay to be answered
     * and the conversation stays flagged, so staff still see it.
     */
    public function resume(Conversation $conversation): void
    {
        $conversation->forceFill([
            'mode' => ConversationMode::Assistant,
            'needs_attention' => true,
            'handover_reason' => 'no_staff_reply',
            'waiting_since' => null,
        ])->save();

        Audit::record('conversations.assistant_resumed', 'No staff reply: the assistant answers the guest again', subject: $conversation, properties: [
            'handed_over_at' => $conversation->handed_over_at?->toIso8601String(),
        ]);
    }

    public function pause(Conversation $conversation, ?User $by = null): void
    {
        $conversation->forceFill(['mode' => ConversationMode::Paused])->save();

        Audit::record('conversations.paused', 'Assistant paused for the conversation', subject: $conversation, causer: $by);
    }
}
