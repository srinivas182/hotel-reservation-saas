<?php

declare(strict_types=1);

namespace App\Domains\Deposits;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Audit;
use App\Domains\Billing\Payments\PaymentFailed;
use App\Domains\Billing\Payments\RefundsPayments;
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
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
 * sends a screenshot), through the same path. Since 0.36 a hotel may take
 * several providers at once, and bank transfers with a reference and a
 * deadline, approved by staff.
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

    /** Bank transfer: pay within this many hours (and at least TRANSFER_DAYS_BEFORE_ARRIVAL before arrival). */
    public const TRANSFER_HOURS = 48;

    public const TRANSFER_DAYS_BEFORE_ARRIVAL = 2;

    /** Deposits are asked for and can be paid through the link in this hotel. */
    public function online(): bool
    {
        $settings = HotelAssistantSettings::current();

        return $settings->deposit_required && $settings->deposit_online && $this->acceptsPayments();
    }

    /**
     * 0.36: the link can take a payment: a provider account that works
     * (CinetPay, Stripe…) or the hotel's bank details for a transfer.
     */
    public function acceptsPayments(): bool
    {
        return MerchantAccount::usableAll()->isNotEmpty() || HotelAssistantSettings::current()->takesBankTransfer();
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
        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true) || ! $this->acceptsPayments() || ! HotelAssistantSettings::current()->deposit_online) {
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
     * 0.31: the discount (percent) for paying the whole stay before arrival,
     * online or (0.36) by bank transfer; 0 when the hotel does not offer it
     * or cannot take payments through the link.
     */
    public function prepayPercent(): int
    {
        $settings = HotelAssistantSettings::current();

        return $settings->deposit_online && $this->acceptsPayments() ? max(0, min(50, $settings->prepay_discount_percent)) : 0;
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
     * The guest chose what to pay and how: start a payment with that
     * provider (0.36: the hotel may have several; null = the first).
     *
     * @param  'deposit'|'full'  $choice
     */
    public function start(DepositLink $link, string $choice, ?string $driver = null): DepositAttempt
    {
        if (! $this->canPay($link)) {
            throw new DepositNotPossible(__('This payment link is no longer valid. Write to the hotel on WhatsApp.'));
        }
        $this->refuseWhileTransferChecked($link);

        $accounts = MerchantAccount::usableAll();
        $account = $driver === null ? $accounts->first() : $accounts->firstWhere('driver', $driver);
        if ($account === null) {
            throw new DepositNotPossible(__('Online payment is not available. Write to the hotel on WhatsApp.'));
        }

        $amount = $this->amountFor($link, $choice);
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
                // 0.36.3: only the guest's own email; never the platform's address.
                'email' => (string) ($booking->guest->email ?? ''),
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
     * 0.36: the ways a guest can pay through the link, for the assistant.
     *
     * @return list<string>
     */
    public function payNowWays(): array
    {
        $drivers = MerchantAccount::usableAll()->pluck('driver')->all();

        return array_values(array_filter([
            array_intersect($drivers, ['cinetpay', 'simulation']) !== [] ? 'mobile money (Wave, Orange Money, MTN MoMo, Moov Money)' : null,
            in_array('stripe', $drivers, true) || in_array('cinetpay', $drivers, true) ? 'card' : null,
            HotelAssistantSettings::current()->takesBankTransfer() ? 'bank transfer' : null,
        ]));
    }

    /**
     * What the guest pays for a choice: the balance link's amount due, the
     * whole stay (at the pay-now price when it applies), or the deposit.
     *
     * @param  'deposit'|'full'  $choice
     */
    public function amountFor(DepositLink $link, string $choice): int
    {
        $amount = match (true) {
            $link->purpose === 'balance' => $this->dueNow($link->booking),
            $choice === 'full' && HotelAssistantSettings::current()->deposit_allow_full => $this->fullAmount($link),
            default => $link->deposit_minor,
        };

        if ($amount <= 0) {
            throw new DepositNotPossible(__('Nothing is left to pay on this booking.'));
        }

        return $amount;
    }

    /**
     * 0.36: when a bank transfer must arrive: within 48 hours, at least two
     * days before arrival, and for a deposit before the rooms are released.
     * Null when there is no longer time for a transfer.
     */
    public function transferDeadline(DepositLink $link): ?CarbonImmutable
    {
        if (! HotelAssistantSettings::current()->takesBankTransfer() || ! $this->canPay($link)) {
            return null;
        }

        $hotel = $this->context->hotel();
        $beforeArrival = CarbonImmutable::parse($link->booking->check_in->toDateString(), $hotel->timezone)
            ->subDays(self::TRANSFER_DAYS_BEFORE_ARRIVAL)->setTime(23, 59)->utc();
        $deadline = now()->toImmutable()->addHours(self::TRANSFER_HOURS)->min($beforeArrival);

        if ($link->purpose === 'deposit' && $link->expires_at !== null) {
            $deadline = $deadline->min($link->expires_at);
        }

        // Less than two hours to transfer is no real offer.
        return $deadline->lt(now()->addHours(2)) ? null : $deadline;
    }

    /**
     * 0.36: the guest will pay by bank transfer. The attempt carries the
     * amount (the pay-now price for the whole stay), a short reference to
     * write on the transfer and the deadline. Nothing is paid until staff
     * approve it after seeing the money on the hotel's account.
     *
     * @param  'deposit'|'full'  $choice
     */
    public function startTransfer(DepositLink $link, string $choice): DepositAttempt
    {
        $this->refuseWhileTransferChecked($link);
        $deadline = $this->transferDeadline($link);
        if ($deadline === null) {
            throw new DepositNotPossible(__('A bank transfer is no longer possible for this booking: it would not arrive in time. Pay online or at the hotel.'));
        }

        $amount = $this->amountFor($link, $choice);

        // One transfer waiting per link: a new choice replaces the old one.
        DepositAttempt::query()->where('deposit_link_id', $link->id)->where('method', 'bank_transfer')->where('status', 'pending')->whereNull('proof_at')
            ->update(['status' => 'failed', 'failure' => 'Replaced by a new transfer choice.', 'updated_at' => now()]);

        $attempt = DepositAttempt::query()->create([
            'deposit_link_id' => $link->id,
            'merchant_account_id' => null,
            'method' => 'bank_transfer',
            'reference' => 'VIR-'.$link->booking->reference.'-'.strtoupper(Str::random(4)),
            'amount_minor' => $amount,
            'currency' => $link->currency,
            'expires_at' => $deadline,
        ]);

        Audit::record('deposits.transfer_started', 'The guest chose to pay by bank transfer', subject: $link->booking, properties: ['reference' => $attempt->reference, 'amount_minor' => $amount, 'deadline' => $deadline->toIso8601String()]);

        return $attempt;
    }

    /**
     * 0.36: the guest uploaded the transfer receipt: staff check their bank
     * account, then approve or reject.
     */
    public function attachProof(DepositAttempt $attempt, UploadedFile $file): void
    {
        if (! $attempt->isTransfer() || $attempt->status !== 'pending') {
            throw new DepositNotPossible(__('This transfer is no longer waiting for a receipt.'));
        }

        $path = $file->store('deposit-proofs/'.$attempt->hotel_id, 'local');
        if ($attempt->proof_path !== null) {
            Storage::disk('local')->delete($attempt->proof_path);
        }
        $attempt->forceFill(['proof_path' => $path, 'proof_at' => now()])->save();

        $booking = $attempt->link->booking->loadMissing('guest');
        Audit::record('deposits.transfer_proof', 'Bank transfer receipt received', subject: $booking, properties: ['reference' => $attempt->reference]);

        $this->alertQuietly(AlertKind::DepositProof, (string) __(':guest sent a bank transfer receipt of :amount for :reference: check your bank account, then approve it in Deposits', [
            'guest' => (string) $booking->guest?->name(),
            'amount' => MoneyFormatter::format(Money::ofMinor($attempt->amount_minor, $attempt->currency), $this->context->hotel()->default_locale),
            'reference' => $attempt->reference,
        ]), $booking->id);
    }

    /**
     * 0.36: staff saw the transfer on the hotel's bank account. Recorded
     * like any payment: room bill, pay-now discount, confirmation, receipt.
     */
    public function approveTransfer(DepositAttempt $attempt, User $by): bool
    {
        $marked = DB::transaction(function () use ($attempt, $by): bool {
            $locked = DepositAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (! $locked->isTransfer() || $locked->status !== 'pending') {
                return false;
            }
            $locked->forceFill(['status' => 'paid', 'decided_by' => $by->getKey(), 'decided_at' => now()])->save();

            return true;
        });

        if (! $marked) {
            throw new DepositNotPossible(__('This transfer was already handled.'));
        }

        $attempt->refresh();
        Audit::record('deposits.transfer_approved', 'Bank transfer approved', subject: $attempt->link->booking, causer: $by, properties: ['reference' => $attempt->reference, 'amount_minor' => $attempt->amount_minor]);

        return $this->markPaid($attempt->link, $attempt->amount_minor, 'bank_transfer', 'manual', $attempt->reference, $by);
    }

    /**
     * 0.36: the money is not on the account. The guest is told and can pay
     * another way; nothing is recorded.
     */
    public function rejectTransfer(DepositAttempt $attempt, User $by, string $reason): void
    {
        if (! $attempt->isTransfer() || $attempt->status !== 'pending') {
            throw new DepositNotPossible(__('This transfer was already handled.'));
        }

        $attempt->forceFill(['status' => 'rejected', 'failure' => mb_substr($reason, 0, 300), 'decided_by' => $by->getKey(), 'decided_at' => now()])->save();
        Audit::record('deposits.transfer_rejected', 'Bank transfer rejected', subject: $attempt->link->booking, causer: $by, properties: ['reference' => $attempt->reference, 'reason' => $reason]);
        TellGuestAboutDeposit::dispatch($attempt->deposit_link_id, 'transfer_rejected', $attempt->id);
    }

    /**
     * 0.36: transfers past their deadline without a receipt end; the guest
     * is told the pay-now offer is over (current hotel). Those with a
     * receipt stay for staff to decide.
     */
    public function expireTransfers(): int
    {
        $count = 0;
        $due = DepositAttempt::query()->where('method', 'bank_transfer')->where('status', 'pending')
            ->whereNull('proof_at')->where('expires_at', '<', now())->get();

        foreach ($due as $attempt) {
            $attempt->forceFill(['status' => 'expired'])->save();
            TellGuestAboutDeposit::dispatch($attempt->deposit_link_id, 'transfer_expired', $attempt->id);
            $count++;
        }

        return $count;
    }

    /**
     * 0.37.1: a bank transfer whose receipt the hotel is checking. Until
     * staff approve or reject it, the guest cannot pay again, so the stay
     * is never paid twice.
     */
    public function transferUnderReview(DepositLink $link): ?DepositAttempt
    {
        return DepositAttempt::query()->where('deposit_link_id', $link->id)->where('method', 'bank_transfer')->where('status', 'pending')->whereNotNull('proof_at')->latest()->first();
    }

    private function refuseWhileTransferChecked(DepositLink $link): void
    {
        if ($this->transferUnderReview($link) !== null) {
            throw new DepositNotPossible(__('The hotel is checking your bank transfer receipt. If the transfer is not found, you can pay another way.'));
        }
    }

    /**
     * The bank transfer waiting on a link, if any.
     */
    public function pendingTransfer(DepositLink $link): ?DepositAttempt
    {
        return DepositAttempt::query()->where('deposit_link_id', $link->id)->where('method', 'bank_transfer')->where('status', 'pending')->latest()->first();
    }

    /**
     * Check a payment with the provider and, once paid, apply it.
     */
    public function settle(DepositAttempt $attempt): DepositAttempt
    {
        // Bank transfers are approved by staff, never by a provider.
        if ($attempt->status !== 'pending' || $attempt->isTransfer() || $attempt->account === null) {
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

                // 0.37.1: paid another way: a transfer still waiting for its receipt ends quietly.
                DepositAttempt::query()->where('deposit_link_id', $locked->id)->where('method', 'bank_transfer')->where('status', 'pending')->whereNull('proof_at')
                    ->update(['status' => 'failed', 'failure' => 'Paid another way.', 'updated_at' => now()]);

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

        // 0.39.1: the guest hears that the money is on its way back.
        TellGuestAboutDeposit::dispatch($link->id, 'refunded');
    }

    /**
     * 0.39.1: the provider that took this payment, when it can refund it
     * from here (Stripe cards; the staging simulation). Null for transfers,
     * cash and mobile money: those refunds stay manual.
     */
    public function onlineRefunder(DepositLink $link): ?RefundsPayments
    {
        if ($link->refund_status !== 'due' || $link->paid_via !== 'online' || $link->provider_reference === null) {
            return null;
        }

        $accountId = DepositAttempt::query()->where('deposit_link_id', $link->id)->where('status', 'paid')->whereNotNull('merchant_account_id')->value('merchant_account_id');
        $account = $accountId === null ? null : MerchantAccount::query()->whereKey($accountId)->first();
        $driver = $account?->driver();

        return $driver instanceof RefundsPayments && $driver->canRefund($link->provider_reference) ? $driver : null;
    }

    /**
     * 0.39.1: send the money back through the provider, then record it as
     * refunded. One refund per deposit, even on a double click: the
     * provider gets the same idempotency key and the link is locked.
     */
    public function refundOnline(DepositLink $link, User $by): string
    {
        $refunder = $this->onlineRefunder($link);

        if ($refunder === null) {
            throw new DepositNotPossible(__('This payment cannot be refunded from here: send the money back yourself, then record it.'));
        }

        $lock = Cache::lock('deposit-refund:'.$link->id, 60);

        if (! $lock->get()) {
            throw new DepositNotPossible(__('This refund is already being made.'));
        }

        try {
            $link->refresh();
            if ($link->refund_status !== 'due') {
                throw new DepositNotPossible(__('There is no refund to make on this deposit.'));
            }

            try {
                $refundId = $refunder->refund((string) $link->provider_reference, (int) $link->refund_due_minor, $link->currency, 'deposit-refund-'.$link->id);
            } catch (PaymentFailed $e) {
                Audit::record('deposits.refund_failed', 'Online refund refused by the provider', subject: $link->booking, causer: $by, properties: ['link' => $link->id, 'error' => $e->getMessage()]);

                throw new DepositNotPossible(__('The refund was refused: :reason', ['reason' => $e->getMessage()]));
            }

            $this->markRefunded($link, $by, (string) __('Refunded online (:id)', ['id' => $refundId]));

            return $refundId;
        } finally {
            $lock->release();
        }
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
