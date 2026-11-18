<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts;

use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestCard;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The WhatsApp backup of an app notification (Sprint D): if the person
 * has not opened it after a few minutes, the alert goes by WhatsApp, so
 * nothing is missed on a phone with notifications blocked.
 */
final class SendWhatsAppIfUnopened implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    /** Retry policy (S16): a repeat is harmless, the alert is sent once. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** Seconds before a stuck attempt is stopped. */
    public int $timeout = 60;

    public function __construct(
        public readonly int $alertId,
        public readonly string $kind,
        public readonly string $details,
        public readonly bool $urgent = false,
    ) {}

    public function handle(StaffAlerts $alerts): void
    {
        $alert = StaffAlert::query()->find($this->alertId);

        if ($alert === null || $alert->opened_at !== null || $alert->how !== 'push' || $alert->user_id === null) {
            return;
        }

        // Claim it: a second copy of this job does nothing.
        if (StaffAlert::query()->whereKey($alert->id)->where('how', 'push')->whereNull('opened_at')->update(['how' => 'push_unopened']) !== 1) {
            return;
        }

        $settings = StaffAlertSettings::query()->with('user')->where('user_id', $alert->user_id)->first();
        $kind = AlertKind::tryFrom($this->kind);

        if ($settings?->user === null || $settings->alert_phone === null || $kind === null) {
            return;
        }

        $card = null;
        if ($kind === AlertKind::NewRequest && $alert->subject_id !== null) {
            $request = BookingRequest::query()->find($alert->subject_id);
            if ($request === null || $request->status !== BookingRequestStatus::Pending) {
                return; // already answered: no need to bother anyone
            }
            $card = new BookingRequestCard($request);
        }

        $alerts->sendTo($settings, $settings->user, $kind, $this->details, $alert->subject_id, $card, repeat: true, urgent: $this->urgent, whatsappOnly: true);
    }
}
