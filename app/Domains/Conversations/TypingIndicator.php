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

    /** 0.37.6: the guest message that got an ⏳, to take it off again. */
    private ?Message $reacted = null;

    public function __construct(private readonly GraphClient $graph) {}

    /**
     * @param  bool  $react  also put ⏳ on the guest's message (0.37.6),
     *                       taken off by stop(): only where stop() follows
     */
    public function start(Message $guestMessage, bool $react = false): void
    {
        $this->message = $guestMessage;
        $this->shownAt = null;

        if ($this->show() && $react && HotelAssistantSettings::current()->waiting_reaction) {
            $this->react($guestMessage, '⏳');
            $this->reacted = $guestMessage;
        }
    }

    public function keepAlive(): void
    {
        if ($this->message !== null && $this->shownAt !== null && (now()->getPreciseTimestamp(3) / 1000) - $this->shownAt >= self::REFRESH_SECONDS) {
            $this->show();
        }
    }

    public function stop(): void
    {
        if ($this->reacted !== null) {
            $this->react($this->reacted, '');
        }

        $this->message = null;
        $this->shownAt = null;
        $this->reacted = null;
    }

    private function react(Message $message, string $emoji): void
    {
        $account = WhatsAppAccount::query()->first();
        $to = $message->loadMissing('conversation.contact')->conversation?->contact?->wa_id;

        if ($account !== null && is_string($to) && $to !== '' && $message->wamid !== null) {
            $this->graph->react($account->phone_number_id, (string) $account->token, $to, $message->wamid, $emoji);
        }
    }

    /** Whether the indicator applies (automatic mode, a WhatsApp message, a usable number). */
    private function show(): bool
    {
        $message = $this->message;
        if ($message === null || $message->direction !== 'in' || $message->wamid === null || $message->wamid === '') {
            return false;
        }

        if (HotelAssistantSettings::current()->effectiveMode() !== AssistantMode::Automatic) {
            return false;
        }

        $account = WhatsAppAccount::query()->first();
        if ($account === null || ! $account->status->canSend()) {
            return false;
        }

        $this->shownAt = (now()->getPreciseTimestamp(3) / 1000);
        $this->graph->typing($account->phone_number_id, (string) $account->token, $message->wamid);

        return true;
    }
}
