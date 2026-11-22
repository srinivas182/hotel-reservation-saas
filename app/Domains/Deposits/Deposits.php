<?php

declare(strict_types=1);

namespace App\Domains\Deposits;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Audit;
use App\Domains\Billing\Payments\PaymentFailed;
use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Exceptions\BookingActionNotAllowed;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Deposits\Jobs\TellGuestAboutDeposit;
use App\Domains\Deposits\Models\DepositAttempt;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\Deposits\Models\MerchantAccount;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\Folio;
use App\Domains\Folio\FolioLine;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deposits paid by mobile money (Sprint F), in the current hotel.
 *
 * A tentative booking gets one private link. The guest pays the deposit
 * (or the whole stay) through the hotel's own merchant account; the
 * payment is always checked with the provider, server to server, before
 * anything changes. Then, once only: the payment goes on the room bill,
 * the booking is confirmed, the guest gets a receipt and staff are told.
 * Without a merchant account, staff confirm a transfer by hand (the guest
 * sends a screenshot), through the same path.
 */
final class Deposits
{
    /** A reminder goes out this long before the rooms are released. */
    public const REMIND_BEFORE_MINUTES = 120;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Folio $folio,
        private readonly BookingLifecycle $lifecycle,
        private readonly StaffAlerts $alerts,
    ) {}

    /** Deposits are asked for and can be paid online in this hotel. */
    public function online(): bool
    {
        $settings = HotelAssistantSettings::current();

        return $settings->deposit_required && $settings->deposit_online && (MerchantAccount::current()?->usable() ?? false);
    }

    /**
     * The open link of a tentative booking, created on first use.
     */
    public function linkFor(Booking $booking, ?User $by = null): DepositLink
    {
        $open = DepositLink::query()->where('booking_id', $booking->id)->where('purpose', 'deposit')->where('status', 'open')->latest()->first();
        if ($open !== null) {
            if ($open->expires_at?->toIso8601String() !== $booking->tentative_until?->toIso8601String()) {
                $open->forceFill(['expires_at' => $booking->tentative_until])->save();
            }

            return $open;
        }

        if ($booking->status !== BookingStatus::Tentative) {
            throw new DepositNotPossible(__('Only a tentative booking waits for a deposit.'));
        }

        $token = Str::random(40);
        $link = DepositLink::query()->create([
            'booking_id' => $booking->id,
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'deposit_minor' => $this->depositOf($booking),
            'currency' => $booking->currency,
            'expires_at' => $booking->tentative_until,
            'created_by' => $by?->getKey(),
        ]);

        Audit::record('deposits.link_created', 'Deposit link created', subject: $booking, causer: $by, properties: ['link' => $link->id, 'deposit_minor' => $link->deposit_minor]);

        return $link;
    }

    /**
     * Sprint G: a link to pay what is left of a confirmed stay before
     * arriving, when the hotel takes mobile money online. Null when nothing
     * is left to pay.
     */
    public function balanceLinkFor(Booking $booking): ?DepositLink
    {
        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true) || ! (MerchantAccount::current()?->usable() ?? false) || ! HotelAssistantSettings::current()->deposit_online) {
            return null;
        }

        $balance = $this->dueNow($booking);
        if ($balance <= 0) {
            return null;
        }

        $open = DepositLink::query()->where('booking_id', $booking->id)->where('purpose', 'balance')->where('status', 'open')->latest()->first();
        if ($open !== null) {
            if ($open->deposit_minor !== $balance) {
                $open->forceFill(['deposit_minor' => $balance])->save();
            }

            return $open;
        }

        $token = Str::random(40);
        $link = DepositLink::query()->create([
            'booking_id' => $booking->id,
            'purpose' => 'balance',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'deposit_minor' => $balance,
            'currency' => $booking->currency,
            // Valid until the morning of departure, hotel time.
            'expires_at' => $booking->check_out->shiftTimezone($this->context->hotel()->timezone)->setTime(12, 0)->utc(),
        ]);

        Audit::record('deposits.balance_link_created', 'Balance link created', subject: $booking, properties: ['link' => $link->id, 'balance_minor' => $balance]);

        return $link;
    }

    /** The link can be paid now: open, and the booking in the state it was made for. */
    public function canPay(DepositLink $link): bool
    {
        $status = $link->booking->status;

        return $link->isOpen() && ($link->purpose === 'balance'
            ? in_array($status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true)
            : $status === BookingStatus::Tentative);
    }

    public function url(DepositLink $link): string
    {
        return route('deposits.show', $link->token);
    }

    /**
     * The deposit the hotel asks for, rounded up to what mobile money can
     * take (multiples of 5 F CFA).
     */
    public function depositOf(Booking $booking): int
    {
        $percent = HotelAssistantSettings::current()->deposit_percent;
        $deposit = Money::ofMinor($booking->total_minor, $booking->currency)->multipliedBy($percent)->dividedBy(100, RoundingMode::Up);

        return self::payable($deposit->getMinorAmount()->toInt(), $booking->currency);
    }

    /**
     * What is left to pay on the whole stay (the "pay everything" choice),
     * with the pay-now discount when it applies.
     */
    public function fullAmount(DepositLink $link): int
    {
        return self::payable(max($link->deposit_minor, $this->dueNow($link->booking)), $link->currency);
    }

    /**
     * 0.31: the discount (percent) for paying the whole stay online before
     * arrival; 0 when the hotel does not offer it or cannot take payments online.
     */
    public function prepayPercent(): int
    {
        $settings = HotelAssistantSettings::current();

        return $settings->deposit_online && (MerchantAccount::current()?->usable() ?? false) ? max(0, min(50, $settings->prepay_discount_percent)) : 0;
    }

    /**
     * The pay-now price of the whole stay, when it still applies: discount
     * on, nothing paid yet, not already applied. Null otherwise.
     */
    public function prepayTotal(Booking $booking): ?int
    {
        $percent = $this->prepayPercent();

        if ($percent === 0 || $booking->prepaid_at !== null || $this->folio->payments($booking) > 0
            || ! in_array($booking->status, [BookingStatus::Tentative, BookingStatus::Confirmed], true)) {
            return null;
        }

        return $this->prepayPrice($booking->total_minor, $booking->currency);
    }

    /** The pay-now price of a total, or null when the hotel offers no pay-now discount. */
    public function prepayPrice(int $totalMinor, string $currency): ?int
    {
        $percent = $this->prepayPercent();
        if ($percent === 0) {
            return null;
        }

        $discounted = Money::ofMinor($totalMinor, $currency)->multipliedBy(100 - $percent)->dividedBy(100, RoundingMode::Up);

        return self::payable($discounted->getMinorAmount()->toInt(), $currency);
    }

    /** What paying everything now costs: the pay-now price, else the balance. */
    public function dueNow(Booking $booking): int
    {
        return $this->prepayTotal($booking) ?? self::payable($this->folio->balance($booking), $booking->currency);
    }

    /** Amounts in F CFA go up to a multiple of 5 (provider rule). */
    public static function payable(int $minor, string $currency): int
    {
        return in_array($currency, ['XOF', 'XAF'], true) ? (int) (ceil($minor / 5) * 5) : $minor;
    }

    /**
     * The guest chose what to pay: start a payment with the provider.
     *
     * @param  'deposit'|'full'  $choice
     */
    public function start(DepositLink $link, string $choice): DepositAttempt
    {
        if (! $this->canPay($link)) {
            throw new DepositNotPossible(__('This payment link is no longer valid. Write to the hotel on WhatsApp.'));
        }

        $account = MerchantAccount::current();
        if ($account === null || ! $account->usable()) {
            throw new DepositNotPossible(__('Online payment is not available. Write to the hotel on WhatsApp.'));
        }

        $settings = HotelAssistantSettings::current();
        $amount = match (true) {
            $link->purpose === 'balance' => $this->dueNow($link->booking),
            $choice === 'full' && $settings->deposit_allow_full => $this->fullAmount($link),
            default => $link->deposit_minor,
        };
        if ($amount <= 0) {
            throw new DepositNotPossible(__('Nothing is left to pay on this booking.'));
        }
        $hotel = $this->context->hotel();
        $booking = $link->booking->loadMissing('guest');

        $attempt = DepositAttempt::query()->create([
            'deposit_link_id' => $link->id,
            'merchant_account_id' => $account->id,
            'reference' => 'DEP'.strtoupper(Str::random(14)),
            'amount_minor' => $amount,
            'currency' => $link->currency,
        ]);

        try {
            $started = $account->driver()->start($attempt->payable(), [
                'description' => (string) __('Booking :reference — :hotel', ['reference' => $booking->reference, 'hotel' => $hotel->name]),
                'return_url' => route('deposits.back', $link->token),
                'notify_url' => route('webhooks.deposits', $account->id),
                'name' => $booking->guest?->name() ?? '',
                'email' => $booking->guest->email ?? (string) config('mail.from.address'),
            ]);
        } catch (PaymentFailed $e) {
            $attempt->forceFill(['status' => 'failed', 'failure' => mb_substr($e->getMessage(), 0, 300)])->save();
            Audit::record('deposits.start_failed', 'Deposit payment could not start', subject: $booking, properties: ['reference' => $attempt->reference, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            throw new DepositNotPossible(__('The payment could not start. Try again in a moment, or write to the hotel on WhatsApp.'));
        }

        $attempt->forceFill(['checkout_url' => $started['url'], 'provider_token' => $started['token'] ?? null])->save();

        return $attempt;
    }

    /**
     * Check a payment with the provider and, once paid, apply it.
     */
    public function settle(DepositAttempt $attempt): DepositAttempt
    {
        if ($attempt->status !== 'pending') {
            return $attempt;
        }

        $result = $attempt->account->driver()->verify($attempt->payable());

        if ($result->status === 'failed') {
            $attempt->forceFill(['status' => 'failed', 'failure' => mb_substr((string) $result->message, 0, 300)])->save();

            return $attempt;
        }

        if ($result->status !== 'paid') {
            return $attempt;
        }

        if ($result->amountMinor !== $attempt->amount_minor || strtoupper((string) $result->currency) !== $attempt->currency) {
            $attempt->forceFill(['status' => 'failed', 'failure' => 'Amount or currency differs from the provider\'s confirmation.'])->save();
            Audit::record('deposits.mismatch', 'Deposit payment did not match', subject: $attempt->link->booking, properties: ['reference' => $attempt->reference, 'expected' => $attempt->amount_minor, 'confirmed' => $result->amountMinor]);

            return $attempt;
        }

        $marked = DB::transaction(function () use ($attempt): bool {
            $locked = DepositAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status !== 'pending') {
                return false;
            }
            $locked->forceFill(['status' => 'paid'])->save();

            return true;
        });

        $attempt->refresh();

        if ($marked) {
            $this->markPaid($attempt->link, $attempt->amount_minor, $result->method ?? 'mobile_money', 'online', $result->providerReference, null);
        }

        return $attempt;
    }

    private function applyPrepay(Booking $booking, int $prepay): void
    {
        $discount = max(0, $booking->total_minor - $prepay);
        $note = (string) __('Paid in full online before arrival, with a :percent% pay-now discount', ['percent' => $this->prepayPercent()]);

        $booking->forceFill([
            'total_minor' => $booking->total_minor - $discount,
            'prepay_discount_minor' => $discount,
            'prepaid_at' => now(),
            'payment_note' => trim(($booking->payment_note ?? '').' '.$note),
        ])->save();

        Audit::record('deposits.prepaid', 'Stay paid in full with the pay-now discount', subject: $booking, properties: ['discount_minor' => $discount, 'paid_minor' => $prepay]);
    }

    /**
     * Staff saw the money arrive (a transfer, cash at a counter…).
     */
    public function confirmManually(DepositLink $link, User $by, string $method, ?int $amount = null): bool
    {
        return $this->markPaid($link, $amount ?? $link->deposit_minor, $method, 'manual', null, $by);
    }

    /**
     * Apply a payment, once: room bill, confirmation, receipt, alerts.
     * A payment that arrives after the rooms were released is still
     * recorded (the money is there), and staff are asked to rebook or refund.
     */
    public function markPaid(DepositLink $link, int $amount, string $method, string $via, ?string $providerReference, ?User $by): bool
    {
        $hotel = $this->context->hotel();

        try {
            $paid = DB::transaction(function () use ($link, $amount, $method, $via, $providerReference, $by, $hotel): bool {
                $locked = DepositLink::query()->lockForUpdate()->findOrFail($link->id);
                if ($locked->status === 'paid') {
                    return false;
                }

                $booking = Booking::query()->findOrFail($locked->booking_id);
                $prepay = $this->prepayTotal($booking);
                $receipt = 'R'.HotelClock::today($hotel)->format('ymd').'-'.strtoupper(substr($locked->id, -6));
                $line = $this->folio->pay($booking, Money::ofMinor($amount, $locked->currency), $method, $receipt, $by, 'deposit', $locked->id);

                // 0.31: the whole stay paid at the pay-now price: the discount goes on the booking.
                if ($prepay !== null && $amount >= $prepay) {
                    $this->applyPrepay($booking, $prepay);
                }

                $locked->forceFill([
                    'status' => 'paid',
                    'paid_minor' => $amount,
                    'method' => $method,
                    'paid_via' => $via,
                    'paid_at' => now(),
                    'confirmed_by' => $by?->getKey(),
                    'receipt_number' => $receipt,
                    'folio_line_id' => $line->id,
                    'provider_reference' => $providerReference,
                ])->save();
                $link->setRawAttributes($locked->getAttributes(), true);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if (! $paid) {
            return false;
        }

        $booking = $link->booking()->with('guest')->firstOrFail();
        $money = MoneyFormatter::format(Money::ofMinor($amount, $link->currency), $hotel->default_locale);
        $confirmed = false;

        if ($booking->status === BookingStatus::Tentative) {
            try {
                $this->lifecycle->confirm($booking, $by);
                $confirmed = true;
            } catch (BookingActionNotAllowed) {
                // The hold ended in the meantime: handled below.
            }
        }

        Audit::record('deposits.paid', 'Deposit paid', subject: $booking, causer: $by, properties: [
            'link' => $link->id, 'amount_minor' => $amount, 'method' => $method, 'via' => $via, 'receipt' => $link->receipt_number, 'confirmed' => $confirmed,
        ]);

        $standing = in_array($booking->refresh()->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::CheckedOut], true);

        if ($confirmed || $standing) {
            TellGuestAboutDeposit::dispatch($link->id, 'paid');
            $this->alertQuietly(AlertKind::DepositPaid, (string) __(':guest paid :amount (:method) — booking :reference confirmed', [
                'guest' => (string) $booking->guest?->name(), 'amount' => $money, 'method' => \App\Domains\Folio\RestaurantSettings::methodLabel($method), 'reference' => $booking->reference,
            ]), $booking->id);

            return true;
        }

        // Paid after the rooms were released: the money must go back, or the stay be rebooked.
        $link->forceFill(['refund_status' => 'due', 'refund_due_minor' => $amount])->save();
        TellGuestAboutDeposit::dispatch($link->id, 'late');
        $this->alertQuietly(AlertKind::DepositRefund, (string) __(':guest paid :amount after the rooms of :reference were released: rebook or refund', [
            'guest' => (string) $booking->guest?->name(), 'amount' => $money, 'reference' => $booking->reference,
        ]), $booking->id, urgent: true);

        return true;
    }

    /**
     * A booking was cancelled: close its link, and work out whether a paid
     * deposit is owed back under the hotel's policy.
     */
    public function bookingCancelled(Booking $booking, bool $expired): void
    {
        DepositLink::query()->where('booking_id', $booking->id)->where('status', 'open')
            ->update(['status' => $expired ? 'expired' : 'cancelled', 'updated_at' => now()]);

        foreach (DepositLink::query()->where('booking_id', $booking->id)->where('status', 'paid')->whereNull('refund_status')->get() as $link) {
            $refundable = DepositPolicy::refundable($booking, HotelAssistantSettings::current(), $this->context->hotel());
            $link->forceFill([
                'refund_status' => $refundable ? 'due' : 'kept',
                'refund_due_minor' => $refundable ? $link->paid_minor : null,
            ])->save();

            Audit::record('deposits.cancelled', $refundable ? 'Deposit to refund after cancellation' : 'Deposit kept after cancellation', subject: $booking, properties: ['link' => $link->id]);

            if ($refundable) {
                $this->alertQuietly(AlertKind::DepositRefund, (string) __('Refund :amount to :guest (booking :reference cancelled)', [
                    'amount' => MoneyFormatter::format(Money::ofMinor((int) $link->paid_minor, $link->currency), $this->context->hotel()->default_locale),
                    'guest' => (string) $booking->guest?->name(),
                    'reference' => $booking->reference,
                ]), $booking->id);
            }
        }
    }

    /**
     * Staff sent the money back: the payment is cancelled on the room bill.
     */
    public function markRefunded(DepositLink $link, User $by, string $note): void
    {
        if ($link->refund_status !== 'due') {
            throw new DepositNotPossible(__('There is no refund to make on this deposit.'));
        }

        DB::transaction(function () use ($link, $by, $note): void {
            $line = $link->folio_line_id === null ? null : FolioLine::query()->find($link->folio_line_id);
            if ($line !== null && ! FolioLine::query()->where('corrects_line_id', $line->id)->exists()) {
                $this->folio->correct($line, (string) __('Deposit refunded: :note', ['note' => $note]), $by);
            }

            $link->forceFill(['refund_status' => 'done', 'refunded_at' => now(), 'refunded_by' => $by->getKey(), 'refund_note' => $note])->save();
        });

        Audit::record('deposits.refunded', 'Deposit refunded', subject: $link->booking, causer: $by, properties: ['link' => $link->id, 'amount_minor' => $link->refund_due_minor, 'note' => $note]);
    }

    /**
     * Remind guests whose rooms are released soon (current hotel).
     */
    public function remindDue(): int
    {
        $count = 0;
        $links = DepositLink::query()->with('booking')
            ->where('status', 'open')->whereNotNull('sent_at')->whereNull('reminded_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addMinutes(self::REMIND_BEFORE_MINUTES))
            ->get();

        foreach ($links as $link) {
            // Only when the guest had time to pay first (sent at least an hour before).
            if ($link->booking->status !== BookingStatus::Tentative || $link->sent_at?->gt(now()->subHour())) {
                continue;
            }

            $link->forceFill(['reminded_at' => now()])->save();
            TellGuestAboutDeposit::dispatch($link->id, 'reminder');
            $count++;
        }

        return $count;
    }

    private function alertQuietly(AlertKind $kind, string $details, string $subjectId, bool $urgent = false): void
    {
        try {
            $this->alerts->notify($kind, $details, $subjectId, urgent: $urgent);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
