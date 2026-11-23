<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Jobs;

use App\Domains\Bookings\BookingStatus;
use App\Domains\Conversations\Models\Message;
use App\Domains\Deposits\DepositProofCard;
use App\Domains\Deposits\Deposits;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A guest with a deposit to pay sent a photo or a document: probably the
 * screenshot of a transfer (Sprint F). Staff get a card to confirm it,
 * once per 30 minutes per deposit.
 */
final class NoticeDepositProof implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 60;

    public function __construct(public readonly string $messageId) {}

    public function handle(StaffAlerts $alerts): void
    {
        $message = Message::query()->with('conversation.contact')->find($this->messageId);
        $guestId = $message?->conversation?->contact?->guest_id;

        if ($message === null || $guestId === null || ! in_array($message->type, ['image', 'document'], true)) {
            return;
        }

        $link = DepositLink::query()->with('booking.guest')
            ->where('status', 'open')
            ->whereHas('booking', fn ($q) => $q->where('guest_id', $guestId)->where('status', BookingStatus::Tentative->value))
            ->latest()->first();

        if ($link === null || ($link->proof_message_id !== null && $link->updated_at?->gt(now()->subMinutes(30)))) {
            return;
        }

        // 0.37.3: the screenshot may be of a card or mobile-money payment the
        // provider already took: ask the provider first, and never ask staff
        // to approve money the gateway confirms by itself.
        if ($this->paidOnline($link)) {
            return;
        }

        $link->forceFill(['proof_message_id' => $message->id])->save();

        $alerts->notify(AlertKind::DepositProof, DepositProofCard::summary($link, app()->getLocale()), $link->booking_id, new DepositProofCard($link), urgent: true);
    }

    private function paidOnline(DepositLink $link): bool
    {
        $deposits = app(Deposits::class);
        $open = $link->attempts()->with(['account', 'link.booking'])->where('status', 'pending')->whereNotNull('merchant_account_id')
            ->where('created_at', '>', now()->subDay())->latest()->limit(3)->get();

        foreach ($open as $attempt) {
            try {
                $deposits->settle($attempt);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $link->refresh()->status === 'paid';
    }
}
