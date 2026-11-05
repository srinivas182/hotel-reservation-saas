<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts\Listeners;

use App\Domains\Conversations\ConversationNeedsStaff;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;

final class AlertStaffAboutConversation
{
    public function __construct(private readonly StaffAlerts $alerts) {}

    public function handle(ConversationNeedsStaff $event): void
    {
        $conversation = Conversation::query()->with('contact.guest')->find($event->conversationId);

        if ($conversation === null) {
            return;
        }

        $guest = $conversation->contact?->guest?->name() ?? '+'.$conversation->contact?->wa_id;
        $kind = $event->reason === 'guest_waiting' ? AlertKind::GuestWaiting : AlertKind::Handover;
        $details = $kind === AlertKind::Handover && $conversation->handover_reason !== null
            ? $guest.' ('.$conversation->handover_reason.')'
            : $guest;

        $this->alerts->notify($kind, $details, $conversation->id);
    }
}
