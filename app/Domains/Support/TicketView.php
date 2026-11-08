<?php

declare(strict_types=1);

namespace App\Domains\Support;

use App\Domains\Support\Models\SupportMessage;
use App\Domains\Support\Models\SupportTicket;

/**
 * Tickets as shown on the hotel and platform pages.
 */
final class TicketView
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(SupportTicket $t): array
    {
        return [
            'id' => $t->id, 'subject' => $t->subject, 'category' => SupportTicket::categoryLabel($t->category), 'status' => $t->status,
            'priority' => $t->priority, 'late' => $t->late(), 'rating' => $t->rating, 'hotel' => $t->hotel?->name, 'hotel_id' => $t->hotel_id,
            'assignee' => $t->assignee?->name, 'due' => $t->first_response_due_at?->toDateTimeString(), 'updated' => $t->last_activity_at?->toDateTimeString(),
            'created' => $t->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function full(SupportTicket $t, bool $platform): array
    {
        $t->loadMissing(['messages.author', 'requester', 'assignee', 'hotel']);

        return [
            ...self::summary($t),
            'requester' => $t->requester === null ? null : ['name' => $t->requester->name, 'email' => $t->requester->email, 'phone' => $t->requester->phone_e164],
            'context' => $platform ? $t->context : null,
            'notify_whatsapp' => $t->notify_whatsapp,
            'rating_comment' => $t->rating_comment,
            'assignee_id' => $t->assignee_id,
            'messages' => $t->messages->filter(fn (SupportMessage $m): bool => $platform || ! $m->internal)->map(fn (SupportMessage $m): array => [
                'id' => $m->id, 'side' => $m->side, 'internal' => $m->internal, 'body' => $m->body, 'author' => $m->author?->name,
                'at' => $m->created_at?->toDateTimeString(), 'ai_drafted' => $platform && $m->ai_drafted,
                'attachments' => array_map(fn (string $f): string => route('support.attachment', ['ticket' => $t->id, 'file' => $f]), $m->attachments ?? []),
            ])->values(),
        ];
    }
}
