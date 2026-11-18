<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests;

use App\Domains\Audit\Audit;
use App\Domains\BookingRequests\Exceptions\RequestNotPossible;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Rates\Quote\QuoteService;
use App\Domains\StaffActions\ActionToken;
use App\Domains\StaffActions\Models\StaffAction;
use App\Domains\StaffActions\StaffActionFailed;
use App\Domains\StaffActions\StaffActionHandler;
use App\Domains\StaffActions\StaffActionRouter;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;

/**
 * Accept or decline a WhatsApp booking request from the buttons of its
 * card (Sprint B). Decisions go through BookingRequestService exactly as
 * from the dashboard, after the one-minute undo window.
 *
 * Actions: br_accept, br_decline (asks for the reason), br_full,
 * br_dates, br_other.
 */
final class BookingRequestActions implements StaffActionHandler
{
    private const REASONS = ['br_full' => 'full', 'br_dates' => 'dates', 'br_other' => 'other: '];

    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffActionRouter $router,
        private readonly BookingRequestService $requests,
        private readonly QuoteService $quotes,
    ) {}

    public function prefix(): string
    {
        return 'br';
    }

    public function kind(): string
    {
        return 'booking_request';
    }

    public function tap(ActionToken $token, User $user, StaffAlertSettings $settings): void
    {
        $request = BookingRequest::query()->with(['guest', 'category'])->find($token->subject);
        $dashboard = route('hotel.today');

        if ($request === null) {
            $this->router->reply($settings, (string) __('This request no longer exists.'));

            return;
        }

        if (! $settings->approvesOnWhatsApp($user)) {
            $this->router->reply($settings, (string) __('Answering requests from WhatsApp is off for you. Answer in the dashboard: :url (you can turn it on in My alerts).', ['url' => $dashboard]));

            return;
        }

        // Addition 1: a second person is told who handled it, not shown an error.
        if (($message = $this->alreadyHandled($request)) !== null) {
            $this->router->reply($settings, $message);

            return;
        }

        $secondsLeft = (int) now()->diffInSeconds($request->expires_at, false);
        if ($secondsLeft <= 0) {
            $this->router->reply($settings, (string) __('This request has expired. Contact the guest instead.'));

            return;
        }

        $guest = (string) $request->guest?->name();

        if ($token->action === 'br_accept') {
            // A changed price needs the dashboard, where it is confirmed on purpose.
            $now = $this->quotes->quote($request->stay())->total;
            if (! $now->isEqualTo(Money::ofMinor($request->total_minor, $request->currency))) {
                $locale = app()->getLocale();
                $this->router->reply($settings, (string) __('The price is now :now instead of :then quoted to the guest. Confirm the new price in the dashboard: :url', [
                    'now' => MoneyFormatter::format($now, $locale),
                    'then' => MoneyFormatter::format(Money::ofMinor($request->total_minor, $request->currency), $locale),
                    'url' => $dashboard,
                ]));

                return;
            }

            $this->router->schedule($this, $request->id, 'br_accept', null, $user, $settings, (string) __('✅ Accepting the request of :guest (:reference).', ['guest' => $guest, 'reference' => $request->reference]), $secondsLeft);

            return;
        }

        if ($token->action === 'br_decline') {
            $this->router->reply(
                $settings,
                (string) __('Why decline the request of :guest? The guest gets the matching message.', ['guest' => $guest]),
                $this->router->buttons($request->id, $user, $secondsLeft, 'br_full', (string) __('We are full'), 'br_dates', (string) __('Dates not possible'), 'br_other', (string) __('Other reason')),
            );

            return;
        }

        if (isset(self::REASONS[$token->action])) {
            $reason = self::REASONS[$token->action] === 'other: ' ? 'other: '.__('declined from WhatsApp') : self::REASONS[$token->action];
            $this->router->schedule($this, $request->id, 'br_decline', $reason, $user, $settings, (string) __('❌ Declining the request of :guest (:reference).', ['guest' => $guest, 'reference' => $request->reference]), $secondsLeft);
        }
    }

    public function execute(StaffAction $action, User $user): string
    {
        $request = BookingRequest::query()->with('guest')->find($action->subject_id);

        if ($request === null) {
            throw new StaffActionFailed((string) __('This request no longer exists.'));
        }

        try {
            if ($action->action === 'br_accept') {
                $booking = $this->requests->approve($request, $user);
                $message = (string) __('✅ Accepted: booking :reference for :guest. The guest has been told.', ['reference' => $booking->reference, 'guest' => (string) $request->guest?->name()]);
            } else {
                $this->requests->decline($request, $user, (string) $action->reason);
                $message = (string) __('❌ Declined. The guest has been told.');
            }
        } catch (RequestNotPossible $e) {
            // Someone decided in the dashboard during the minute, or the rooms or price changed.
            throw new StaffActionFailed(($this->alreadyHandled($request->refresh(), except: $action) ?? $e->getMessage()).' '.__('Open the dashboard: :url', ['url' => route('hotel.today')]));
        }

        Audit::record('booking_requests.decided_on_whatsapp', 'WhatsApp booking request answered from WhatsApp', subject: $request, causer: $user, properties: [
            'reference' => $request->reference,
            'decision' => $action->action === 'br_accept' ? 'approved' : 'declined',
            'staff_action' => $action->id,
        ]);

        return $message;
    }

    /**
     * Who already dealt with the request, or is about to, if anyone.
     */
    private function alreadyHandled(BookingRequest $request, ?StaffAction $except = null): ?string
    {
        $hotel = $this->context->hotel();
        $inProgress = StaffAction::inProgress($this->kind(), $request->id);

        if ($inProgress !== null && $inProgress->id !== $except?->id) {
            return (string) __(':name is already :doing this request.', [
                'name' => (string) $inProgress->user?->name,
                'doing' => $inProgress->action === 'br_accept' ? __('accepting') : __('declining'),
            ]);
        }

        if ($request->status === BookingRequestStatus::Pending) {
            return null;
        }

        $by = $request->decided_by === null ? null : User::query()->find($request->decided_by);
        $at = $request->decided_at?->setTimezone($hotel->timezone)->format('H:i');

        return match ($request->status) {
            BookingRequestStatus::Approved => (string) __('Already accepted by :name at :time.', ['name' => (string) $by?->name, 'time' => (string) $at]),
            BookingRequestStatus::Declined => (string) __('Already declined by :name at :time.', ['name' => (string) $by?->name, 'time' => (string) $at]),
            BookingRequestStatus::Expired => (string) __('This request has expired. Contact the guest instead.'),
            default => (string) __('This request has already been dealt with.'),
        };
    }
}
