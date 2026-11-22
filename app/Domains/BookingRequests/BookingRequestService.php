<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Audit;
use App\Domains\Availability\Exceptions\NotEnoughRooms;
use App\Domains\Availability\HoldService;
use App\Domains\Availability\Models\InventoryHold;
use App\Domains\BookingRequests\Exceptions\RequestNotPossible;
use App\Domains\BookingRequests\Mail\BookingRequestAlertMail;
use App\Domains\Bookings\BookingChannel;
use App\Domains\Bookings\BookingService;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Exceptions\BookingNotPossible;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\NewBooking;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Hotel\StaffedHours;
use App\Domains\Rates\Coupons\CouponNotValid;
use App\Domains\Rates\Coupons\Coupons;
use App\Domains\Rates\Models\Coupon;
use App\Domains\Rates\Quote\QuoteIssue;
use App\Domains\Rates\Quote\QuoteService;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * WhatsApp booking requests: created by the assistant with the rooms held,
 * then approved, declined or left to expire by reception.
 */
final class BookingRequestService
{
    /** Minutes before expiry when the last call is sent (Sprint D). */
    public const LAST_CALL_MINUTES = 30;

    public function __construct(
        private readonly TenantContext $context,
        private readonly QuoteService $quotes,
        private readonly HoldService $holds,
        private readonly BookingService $bookings,
        private readonly Coupons $coupons,
    ) {}

    /**
     * Create (or replace) the conversation's open request and hold its rooms.
     */
    public function submit(Conversation $conversation, StayRequest $stay, string $guestName, ?string $couponCode = null): BookingRequest
    {
        $guest = $conversation->contact->guest ?? throw new RequestNotPossible('no_guest', 'The conversation has no guest.');
        $name = trim($guestName);

        if (mb_strlen($name) < 2) {
            throw new RequestNotPossible('name_missing', 'Ask the guest for their full name first.');
        }

        $quote = $this->quotes->quote($stay);

        if (! $quote->bookable()) {
            $codes = array_map(fn (QuoteIssue $i): string => $i->code, $quote->issues);

            throw new RequestNotPossible(
                $codes === ['not_available'] ? 'not_available' : 'not_bookable',
                implode(' ', array_map(fn (QuoteIssue $i): string => $i->message, $quote->issues)),
            );
        }

        $hotel = $this->context->hotel();
        $expiresAt = $this->expiryFrom(CarbonImmutable::now());
        $category = RoomCategory::query()->findOrFail($stay->categoryId);

        // 0.31: a coupon the guest gave, checked for this stay before anything is held.
        $coupon = null;
        $discount = Money::zero($quote->total->getCurrency());
        if ($couponCode !== null && trim($couponCode) !== '') {
            try {
                $coupon = $this->coupons->check($couponCode, $stay->nights());
            } catch (CouponNotValid $exception) {
                throw new RequestNotPossible('coupon_not_valid', $exception->getMessage());
            }
            $discount = $this->coupons->discount($coupon, $quote->total);
        }

        try {
            $request = DB::transaction(function () use ($conversation, $guest, $name, $stay, $quote, $hotel, $expiresAt, $category, $coupon, $discount): BookingRequest {
                // One open request per conversation: the previous one is replaced.
                foreach (BookingRequest::query()->where('conversation_id', $conversation->id)->where('status', BookingRequestStatus::Pending->value)->lockForUpdate()->get() as $previous) {
                    $this->releaseHold($previous);
                    $previous->forceFill(['status' => BookingRequestStatus::Withdrawn, 'decided_at' => now()])->save();
                }

                [$first, $last] = array_pad(preg_split('/\s+/', $name, 2) ?: [], 2, null);
                $guest->forceFill(['first_name' => (string) $first, 'last_name' => $last ?? $guest->last_name])->save();

                $request = BookingRequest::query()->create([
                    'reference' => 'R-'.strtoupper(Str::random(6)),
                    'conversation_id' => $conversation->id,
                    'guest_id' => $guest->id,
                    'status' => BookingRequestStatus::Pending,
                    'room_category_id' => $category->id,
                    'check_in' => $stay->checkIn,
                    'check_out' => $stay->checkOut,
                    'rooms' => $stay->rooms,
                    'adults' => $stay->adults,
                    'children_ages' => $stay->childAges,
                    'breakfast' => $stay->breakfast,
                    'extra_bed' => $stay->extraBed,
                    'price_snapshot' => BookingService::snapshot($quote),
                    'total_minor' => $quote->total->minus($discount)->getMinorAmount()->toInt(),
                    'coupon_code' => $coupon?->code,
                    'discount_minor' => $discount->getMinorAmount()->toInt(),
                    'currency' => $hotel->currency,
                    'expires_at' => $expiresAt,
                ]);

                $hold = $this->holds->place(
                    $category,
                    $stay->checkIn,
                    $stay->checkOut,
                    $stay->rooms,
                    sourceType: 'booking_request',
                    sourceId: $request->id,
                    minutes: (int) ceil(now()->diffInMinutes($expiresAt)),
                );

                $request->forceFill(['inventory_hold_id' => $hold->id])->save();

                return $request;
            });
        } catch (NotEnoughRooms $exception) {
            throw new RequestNotPossible('not_available', $exception->getMessage(), ['available' => $exception->available]);
        }

        Audit::record('booking_requests.created', 'WhatsApp booking request received', subject: $request, properties: [
            'reference' => $request->reference,
            'total_minor' => $request->total_minor,
        ]);

        $this->alertStaff($request, 'new');

        return $request;
    }

    /**
     * Rooms are held for the hotel's hold time, and at least until an hour
     * after reception next opens.
     */
    public function expiryFrom(CarbonImmutable $now): CarbonImmutable
    {
        $minutes = HotelAssistantSettings::current()->request_hold_minutes;
        $expires = $now->addMinutes($minutes);
        $opening = StaffedHours::forCurrentHotel($this->context->hotel())->nextOpening($now);

        if ($opening !== null && $opening->addHour()->gt($expires)) {
            $expires = $opening->addHour()->utc();
        }

        return $expires;
    }

    /**
     * Approve, optionally with a changed stay or price. The booking is made
     * from the request's held rooms in one transaction.
     */
    public function approve(
        BookingRequest $request,
        User $by,
        ?StayRequest $changedStay = null,
        bool $priceChangeAcknowledged = false,
        ?Money $overrideTotal = null,
        ?string $overrideReason = null,
    ): Booking {
        $this->assertPending($request);

        $stay = $changedStay ?? $request->stay();
        $quote = $this->quotes->quote($stay);
        // 0.31: the coupon promised to the guest is kept on the price of the stay as approved.
        $couponDiscount = Money::zero($quote->total->getCurrency());
        if ($request->coupon_code !== null && $overrideTotal === null) {
            $coupon = Coupon::query()->where('code', $request->coupon_code)->first();
            $couponDiscount = $coupon === null
                ? Money::ofMinor(min($request->discount_minor, $quote->total->getMinorAmount()->toInt()), $quote->total->getCurrency())
                : $this->coupons->discount($coupon, $quote->total);
            if ($couponDiscount->isPositive()) {
                $overrideTotal = $quote->total->minus($couponDiscount);
                $overrideReason = (string) __('Coupon :code', ['code' => $request->coupon_code]);
            }
        }
        $priced = $overrideTotal ?? $quote->total;
        $quoted = Money::ofMinor($request->total_minor, $request->currency);

        if (! $priced->isEqualTo($quoted) && ! $priceChangeAcknowledged) {
            throw new RequestNotPossible('price_changed', __('The price is now :now instead of :then quoted to the guest. Confirm the new price to approve.', [
                'now' => MoneyFormatter::format($priced, $this->context->hotel()->default_locale),
                'then' => MoneyFormatter::format($quoted, $this->context->hotel()->default_locale),
            ]), ['quoted_minor' => $request->total_minor, 'now_minor' => $priced->getMinorAmount()->toInt()]);
        }

        $guest = $request->guest()->firstOrFail();
        $settings = HotelAssistantSettings::current();

        try {
            $booking = DB::transaction(function () use ($request, $by, $stay, $guest, $overrideTotal, $overrideReason, $settings, $couponDiscount): Booking {
                $this->releaseHold($request);

                $booking = $this->bookings->create(new NewBooking(
                    stay: $stay,
                    guest: ['first_name' => $guest->first_name, 'last_name' => $guest->last_name, 'phone' => $guest->phone_e164, 'email' => $guest->email, 'preferred_locale' => $guest->preferred_locale],
                    channel: BookingChannel::WhatsApp,
                    status: $settings->deposit_required ? BookingStatus::Tentative : BookingStatus::Confirmed,
                    notes: __('From WhatsApp request :reference', ['reference' => $request->reference]),
                    paymentNote: $settings->deposit_required ? (string) __('Deposit of :percent% requested on WhatsApp', ['percent' => $settings->deposit_percent]) : null,
                    overrideTotal: $overrideTotal,
                    overrideReason: $overrideReason,
                    tentativeHours: $settings->deposit_hours,
                ), $by);

                if ($couponDiscount->isPositive()) {
                    $booking->forceFill(['coupon_code' => $request->coupon_code, 'coupon_discount_minor' => $couponDiscount->getMinorAmount()->toInt()])->save();
                    $this->coupons->redeem((string) $request->coupon_code);
                }

                $request->forceFill([
                    'status' => BookingRequestStatus::Approved,
                    'booking_id' => $booking->id,
                    'decided_by' => $by->getKey(),
                    'decided_at' => now(),
                ])->save();

                return $booking;
            });
        } catch (BookingNotPossible $exception) {
            throw new RequestNotPossible('not_available', $exception->getMessage());
        }

        Audit::record('booking_requests.approved', 'WhatsApp booking request approved', subject: $request, causer: $by, properties: [
            'reference' => $request->reference,
            'booking' => $booking->reference,
            'changed' => $changedStay !== null,
            'total_minor' => $booking->total_minor,
        ]);

        BookingRequestDecided::dispatch($request->id);

        return $booking;
    }

    public function decline(BookingRequest $request, User $by, string $reason): void
    {
        $this->assertPending($request);

        DB::transaction(function () use ($request, $by, $reason): void {
            $this->releaseHold($request);
            $request->forceFill([
                'status' => BookingRequestStatus::Declined,
                'decline_reason' => mb_substr(trim($reason), 0, 300),
                'decided_by' => $by->getKey(),
                'decided_at' => now(),
            ])->save();
        });

        Audit::record('booking_requests.declined', 'WhatsApp booking request declined', subject: $request, causer: $by, properties: [
            'reference' => $request->reference,
            'reason' => $reason,
        ]);

        BookingRequestDecided::dispatch($request->id);
    }

    /**
     * Expire pending requests whose hold time has passed (current hotel).
     */
    public function expireDue(): int
    {
        $count = 0;

        foreach (BookingRequest::query()->where('status', BookingRequestStatus::Pending->value)->where('expires_at', '<=', now())->get() as $request) {
            DB::transaction(function () use ($request): void {
                $this->releaseHold($request);
                $request->forceFill(['status' => BookingRequestStatus::Expired, 'decided_at' => now()])->save();
                $request->conversation()->update(['needs_attention' => true, 'handover_reason' => 'request_expired']);
            });

            Audit::record('booking_requests.expired', 'WhatsApp booking request expired without an answer', subject: $request, properties: ['reference' => $request->reference]);
            $this->alertStaff($request, 'expired');
            BookingRequestDecided::dispatch($request->id);
            $count++;
        }

        return $count;
    }

    /**
     * Sprint B: requests nobody answered within the hotel's escalation time
     * (default 15 minutes) go to the owners too, as a card with buttons,
     * once. Not while someone's WhatsApp decision is in its undo minute.
     */
    public function escalateDue(): int
    {
        $count = $this->lastCall();
        $minutes = HotelAssistantSettings::current()->request_escalate_minutes;

        if ($minutes <= 0) {
            return $count;
        }

        $hotel = $this->context->hotel();
        $staff = app(\App\Domains\Access\StaffService::class);
        $alerts = app(StaffAlerts::class);

        $due = BookingRequest::query()->with(['guest', 'category'])
            ->where('status', BookingRequestStatus::Pending->value)
            ->whereNull('escalated_at')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->where('expires_at', '>', now())
            ->get();

        foreach ($due as $request) {
            $request->forceFill(['escalated_at' => now()])->save();

            if (\App\Domains\StaffActions\Models\StaffAction::inProgress('booking_request', $request->id) !== null) {
                continue;
            }

            $sent = 0;
            $owners = $hotel->members()->wherePivot('status', 'active')->get()->filter(fn (User $user): bool => $staff->isOwner($hotel, $user));

            foreach ($owners as $owner) {
                $settings = \App\Domains\StaffAlerts\StaffAlertSettings::query()->where('user_id', $owner->getKey())->whereNotNull('alert_phone')->first();
                if ($settings !== null && $alerts->sendTo($settings, $owner, AlertKind::NewRequest, (string) __('No answer for :minutes minutes', ['minutes' => $minutes]), $request->id, new BookingRequestCard($request, escalated: true), repeat: true)) {
                    $sent++;
                }
            }

            Audit::record('booking_requests.escalated', 'WhatsApp booking request sent to the owners: nobody answered', subject: $request, properties: ['reference' => $request->reference, 'minutes' => $minutes, 'owners_alerted' => $sent]);
            $count++;
        }

        return $count;
    }

    /**
     * Sprint D: a request with 30 minutes left goes once more to those who
     * decide requests, through quiet hours, so it is not lost overnight.
     */
    private function lastCall(): int
    {
        $count = 0;
        $due = BookingRequest::query()->with(['guest', 'category'])
            ->where('status', BookingRequestStatus::Pending->value)
            ->whereNull('last_call_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addMinutes(self::LAST_CALL_MINUTES))
            ->get();

        foreach ($due as $request) {
            $request->forceFill(['last_call_at' => now()])->save();

            if (\App\Domains\StaffActions\Models\StaffAction::inProgress('booking_request', $request->id) !== null) {
                continue;
            }

            app(StaffAlerts::class)->notify(AlertKind::NewRequest, (string) __(':minutes minutes left to answer', ['minutes' => (int) ceil(now()->diffInMinutes($request->expires_at))]), $request->id, new BookingRequestCard($request), urgent: true);
            $count++;
        }

        return $count;
    }

    private function assertPending(BookingRequest $request): void
    {
        $request->refresh();

        if ($request->status !== BookingRequestStatus::Pending) {
            throw new RequestNotPossible('not_pending', __('This request has already been dealt with.'));
        }

        if ($request->expires_at->isPast()) {
            throw new RequestNotPossible('expired', __('This request has expired. Contact the guest instead.'));
        }
    }

    private function releaseHold(BookingRequest $request): void
    {
        $hold = $request->inventory_hold_id === null ? null : InventoryHold::query()->find($request->inventory_hold_id);

        if ($hold !== null) {
            $this->holds->release($hold);
        }
    }

    /**
     * @param  'new'|'expired'  $kind
     */
    private function alertStaff(BookingRequest $request, string $kind): void
    {
        $hotel = $this->context->hotel();
        $request->loadMissing(['guest', 'category']);
        app(StaffAlerts::class)->notify(
            $kind === 'new' ? AlertKind::NewRequest : AlertKind::RequestExpired,
            sprintf('%s, %s, %s → %s', (string) $request->guest?->name(), (string) $request->category?->localizedName($hotel->default_locale, $hotel->default_locale), $request->check_in->toDateString(), $request->check_out->toDateString()),
            $request->id,
            $kind === 'new' ? new BookingRequestCard($request) : null,
        );

        $recipients = $hotel->members()->get()->filter(fn (User $user): bool => $user->can('booking_requests.decide'));

        foreach ($recipients as $user) {
            Mail::to($user->email)->locale($hotel->default_locale)->queue(new BookingRequestAlertMail(
                kind: $kind,
                hotelName: $hotel->name,
                reference: $request->reference,
                guest: (string) $request->guest?->name(),
                stay: sprintf('%s · %s → %s · %d', $request->category?->localizedName($hotel->default_locale, $hotel->default_locale), $request->check_in->toDateString(), $request->check_out->toDateString(), $request->rooms),
                total: MoneyFormatter::format(Money::ofMinor($request->total_minor, $request->currency), $hotel->default_locale),
                expiresAt: $request->expires_at->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
                url: route('hotel.today'),
            ));
        }
    }
}
