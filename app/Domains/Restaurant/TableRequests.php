<?php

declare(strict_types=1);

namespace App\Domains\Restaurant;

use App\Domains\Audit\Audit;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Restaurant\Exceptions\TableNotPossible;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\Models\TableBooking;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Table bookings asked for on WhatsApp: confirmed at once when small and
 * ordinary, otherwise a request for staff with the seats held.
 */
final class TableRequests
{
    /** Notes that always need a person (occasions, groups, privatisation). */
    private const SPECIAL = '/anniversaire|anniversary|birthday|privatis|private|groupe|group|mariage|wedding|séminaire|seminar|conférence|conference|traiteur|catering/iu';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TableBookingService $bookings,
    ) {}

    public function fromWhatsApp(Conversation $conversation, Outlet $outlet, CarbonImmutable $date, string $time, int $covers, string $guestName, ?string $notes = null): TableBooking
    {
        $guest = $conversation->contact->guest ?? throw new TableNotPossible('no_guest', 'The conversation has no guest.');
        $name = trim($guestName);

        if (mb_strlen($name) < 2) {
            throw new TableNotPossible('name_missing', 'Ask the guest for their name first.');
        }

        [$first, $last] = array_pad(preg_split('/\s+/', $name, 2) ?: [], 2, null);
        $needsStaff = ! $outlet->auto_confirm || $covers > $outlet->auto_confirm_max_party || ($notes !== null && preg_match(self::SPECIAL, $notes) === 1);

        $booking = $this->bookings->create(new NewTableBooking(
            outletId: $outlet->id,
            date: $date,
            time: $time,
            covers: $covers,
            guest: ['first_name' => (string) $first, 'last_name' => $last ?? $guest->last_name, 'phone' => $guest->phone_e164, 'preferred_locale' => $guest->preferred_locale],
            source: TableBookingSource::WhatsApp,
            status: $needsStaff ? TableBookingStatus::Requested : TableBookingStatus::Confirmed,
            notes: $notes,
            conversationId: $conversation->id,
            expiresAt: $needsStaff ? $this->expiry($date, $time) : null,
        ));

        if ($needsStaff) {
            app(StaffAlerts::class)->notify(AlertKind::TableRequest, sprintf('%s, %d, %s %s', $name, $covers, $date->toDateString(), $time), $booking->id);
        } else {
            TableBookingChanged::dispatch($booking->id);
        }

        return $booking;
    }

    /**
     * Requests hold their seats for two hours, and never later than one hour
     * before the booking (at least 15 minutes, so staff can still answer).
     */
    public function expiry(CarbonImmutable $date, string $time): CarbonImmutable
    {
        $hotel = $this->context->hotel();
        $start = CarbonImmutable::parse($date->toDateString().' '.$time, $hotel->timezone)->utc();
        $latest = min($start->subHour()->getTimestamp(), now()->addHours(2)->getTimestamp());

        return CarbonImmutable::createFromTimestamp(max($latest, now()->addMinutes(15)->getTimestamp()));
    }

    public function expireDue(): int
    {
        $count = 0;

        foreach (TableBooking::query()->where('status', TableBookingStatus::Requested->value)->where('expires_at', '<=', now())->get() as $booking) {
            $this->bookings->move($booking, TableBookingStatus::Expired);
            $booking->conversation_id !== null && Conversation::query()->whereKey($booking->conversation_id)->update(['needs_attention' => true, 'handover_reason' => 'table_request_expired']);
            TableBookingChanged::dispatch($booking->id);
            Audit::record('table_bookings.request_expired', 'Table request expired without an answer', subject: $booking);
            $count++;
        }

        return $count;
    }
}
