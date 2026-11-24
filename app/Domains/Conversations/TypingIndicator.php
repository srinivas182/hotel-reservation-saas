<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Conversations\Models\Message;
use App\Domains\WhatsApp\Graph\GraphClient;
use App\Domains\WhatsApp\Models\WhatsAppAccount;

/**
 * "Typing…" in the guest's WhatsApp while the assistant prepares its
 * answer (0.37.4), so the guest knows a reply is coming. WhatsApp shows it
 * for 25 seconds at most, so a long answer refreshes it as the assistant
 * works (each tool it uses). Only when the assistant answers by itself:
 * a draft waiting for staff approval would make the guest wait for nothing.
 *
 * One per job (scoped): start() names the guest message, keepAlive()
 * refreshes it.
 */
final class TypingIndicator
{
    /** Refresh a little before WhatsApp's 25 seconds run out. */
    public const REFRESH_SECONDS = 18;

    private ?Message $message = null;

    private ?float $shownAt = null;

    public function __construct(private readonly GraphClient $graph) {}

    public function start(Message $guestMessage): void
    {
        $this->message = $guestMessage;
        $this->shownAt = null;
        $this->show();
    }

    public function keepAlive(): void
    {
        if ($this->message !== null && $this->shownAt !== null && (now()->getPreciseTimestamp(3) / 1000) - $this->shownAt >= self::REFRESH_SECONDS) {
            $this->show();
        }
    }

    public function stop(): void
    {
        $this->message = null;
        $this->shownAt = null;
    }

    private function show(): void
    {
        $message = $this->message;
        if ($message === null || $message->direction !== 'in' || $message->wamid === null || $message->wamid === '') {
            return;
        }

        if (HotelAssistantSettings::current()->effectiveMode() !== AssistantMode::Automatic) {
            return;
        }

        $account = WhatsAppAccount::query()->first();
        if ($account === null || ! $account->status->canSend()) {
            return;
        }

        $this->shownAt = (now()->getPreciseTimestamp(3) / 1000);
        $this->graph->typing($account->phone_number_id, (string) $account->token, $message->wamid);
    }
}
