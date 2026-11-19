<?php

declare(strict_types=1);

namespace App\Domains\Deposits;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\StaffActions\ActionToken;
use App\Domains\StaffActions\Models\StaffAction;
use App\Domains\StaffActions\StaffActionFailed;
use App\Domains\StaffActions\StaffActionHandler;
use App\Domains\StaffActions\StaffActionRouter;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

/**
 * Received / Not received on a payment screenshot (Sprint F). Received
 * records the deposit exactly as from the dashboard, after the one-minute
 * undo window.
 *
 * Actions: dp_received, dp_missing.
 */
final class DepositActions implements StaffActionHandler
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffActionRouter $router,
        private readonly Deposits $deposits,
    ) {}

    public function prefix(): string
    {
        return 'dp';
    }

    public function kind(): string
    {
        return 'deposit';
    }

    public function tap(ActionToken $token, User $user, StaffAlertSettings $settings): void
    {
        $link = DepositLink::query()->with('booking.guest')->find($token->subject);

        if ($link === null) {
            $this->router->reply($settings, (string) __('This deposit no longer exists.'));

            return;
        }

        if (! $user->can('folio.payments')) {
            $this->router->reply($settings, (string) __('Only people who record payments can confirm a deposit.'));

            return;
        }

        if (($message = $this->alreadyHandled($link)) !== null) {
            $this->router->reply($settings, $message);

            return;
        }

        $guest = (string) $link->booking->guest?->name();

        if ($token->action === 'dp_missing') {
            Audit::record('deposits.proof_rejected', 'Payment screenshot: money not received', subject: $link->booking, causer: $user, properties: ['link' => $link->id]);
            $this->router->reply($settings, (string) __('Noted: nothing recorded for :guest. Tell the guest from the inbox if needed: :url', ['guest' => $guest, 'url' => route('hotel.inbox')]));

            return;
        }

        $secondsLeft = $link->expires_at === null ? 3600 : (int) now()->diffInSeconds($link->expires_at, false);
        $this->router->schedule($this, $link->id, 'dp_received', null, $user, $settings, (string) __('✅ Recording the deposit of :guest (:reference).', ['guest' => $guest, 'reference' => $link->booking->reference]), max(0, $secondsLeft));
    }

    public function execute(StaffAction $action, User $user): string
    {
        $link = DepositLink::query()->with('booking.guest')->find($action->subject_id);

        if ($link === null) {
            throw new StaffActionFailed((string) __('This deposit no longer exists.'));
        }

        if (($message = $this->alreadyHandled($link)) !== null) {
            throw new StaffActionFailed($message);
        }

        try {
            $this->deposits->confirmManually($link, $user, 'mobile_money');
        } catch (DepositNotPossible $e) {
            throw new StaffActionFailed($e->getMessage().' '.__('Open the dashboard: :url', ['url' => route('hotel.bookings.show', $link->booking_id)]));
        }

        $booking = $link->booking->refresh();

        return $booking->status === BookingStatus::Confirmed
            ? (string) __('✅ Deposit recorded: booking :reference is confirmed and the guest gets the receipt.', ['reference' => $booking->reference])
            : (string) __('Deposit recorded, but the rooms of :reference had been released: rebook or refund from the dashboard: :url', ['reference' => $booking->reference, 'url' => route('hotel.deposits')]);
    }

    private function alreadyHandled(DepositLink $link): ?string
    {
        if ($link->status === 'paid') {
            return (string) __('Already recorded by :name at :time.', [
                'name' => $link->confirmer->name ?? (string) __('the payment provider'),
                'time' => (string) $link->paid_at?->setTimezone($this->context->hotel()->timezone)->format('H:i'),
            ]);
        }

        if ($link->booking->status !== BookingStatus::Tentative) {
            return (string) __('This booking no longer waits for a deposit.');
        }

        return null;
    }
}
