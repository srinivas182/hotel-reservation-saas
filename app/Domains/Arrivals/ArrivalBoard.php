<?php

declare(strict_types=1);

namespace App\Domains\Arrivals;

use App\Domains\Arrivals\Models\ArrivalCheck;
use App\Domains\Audit\Audit;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Arrivals as staff see them (Sprint G): what each guest answered to the
 * card, tomorrow's arrivals who did not answer, and the evening flag.
 */
final class ArrivalBoard
{
    /** After this hour (hotel time) the day before, a silent guest is flagged. */
    public const FLAG_AT = '18:00';

    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffAlerts $alerts,
    ) {}

    /**
     * The answers of these bookings, for Today's lists.
     *
     * @param  iterable<Booking>  $bookings
     * @return array<string, array{state: string|null, eta: string|null, extras: list<string>, question: bool, flagged: bool}>
     */
    public function answers(iterable $bookings): array
    {
        $ids = [];
        foreach ($bookings as $booking) {
            $ids[] = $booking->id;
        }

        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (ArrivalCheck::query()->whereIn('booking_id', $ids)->get() as $check) {
            $out[$check->booking_id] = self::present($check);
        }

        return $out;
    }

    /**
     * @return array{state: string|null, eta: string|null, extras: list<string>, question: bool, flagged: bool}
     */
    public static function present(ArrivalCheck $check): array
    {
        return [
            'state' => $check->state(),
            'eta' => $check->eta_slot === null ? null : ArrivalSlots::label($check->eta_slot),
            'extras' => array_map(fn (string $key): string => ArrivalExtras::label($key), $check->extras ?? []),
            'question' => $check->question_at !== null,
            'flagged' => $check->flagged_at !== null,
        ];
    }

    /**
     * Tomorrow's confirmed arrivals who got the card, for Today's evening
     * card: who answered, who did not (with their phone, to call).
     *
     * @return list<array{id: string, reference: string, guest: string|null, phone: string|null, state: string|null, eta: string|null, flagged: bool}>
     */
    public function tomorrow(): array
    {
        $tomorrow = HotelClock::today($this->context->hotel())->addDay();

        // One query: Today is drawn every 15 seconds.
        $rows = ArrivalCheck::query()
            ->join('bookings', 'bookings.id', '=', 'arrival_checks.booking_id')
            ->leftJoin('guests', 'guests.id', '=', 'bookings.guest_id')
            ->whereNotNull('arrival_checks.card_sent_at')
            ->where('bookings.check_in', $tomorrow->toDateString())
            ->where('bookings.status', BookingStatus::Confirmed->value)
            ->orderBy('bookings.reference')
            ->get([
                'arrival_checks.*', 'bookings.reference as booking_reference',
                'guests.first_name as guest_first_name', 'guests.last_name as guest_last_name', 'guests.phone_e164 as guest_phone',
            ]);

        return array_values($rows->map(fn (ArrivalCheck $c): array => [
            'id' => $c->booking_id,
            'reference' => (string) $c->getAttribute('booking_reference'),
            'guest' => trim(((string) $c->getAttribute('guest_first_name')).' '.((string) $c->getAttribute('guest_last_name'))) ?: null,
            'phone' => $c->getAttribute('guest_phone') === null ? null : (string) $c->getAttribute('guest_phone'),
            'state' => $c->state(),
            'eta' => $c->eta_slot === null ? null : ArrivalSlots::label($c->eta_slot),
            'flagged' => $c->flagged_at !== null,
        ])->all());
    }

    /**
     * In the evening, flag tomorrow's guests who did not answer, and send
     * staff one alert listing them (current hotel). Returns how many.
     */
    public function flagSilent(): int
    {
        $hotel = $this->context->hotel();
        if (CarbonImmutable::now($hotel->timezone)->format('H:i') < self::FLAG_AT) {
            return 0;
        }

        /** @var Collection<int, ArrivalCheck> $silent */
        $silent = ArrivalCheck::query()->with('booking.guest')
            ->whereNotNull('card_sent_at')->whereNull('confirmed_at')->whereNull('declined_at')->whereNull('flagged_at')
            ->whereHas('booking', fn ($q) => $q->where('status', BookingStatus::Confirmed->value)
                ->where('check_in', HotelClock::today($hotel)->addDay()->toDateString()))
            ->get();

        if ($silent->isEmpty()) {
            return 0;
        }

        ArrivalCheck::query()->whereIn('id', $silent->pluck('id'))->update(['flagged_at' => now(), 'updated_at' => now()]);

        $names = $silent->map(fn (ArrivalCheck $c): string => trim(((string) $c->booking->guest?->name()).' '.((string) $c->booking->guest?->phone_e164)))->implode(', ');
        Audit::record('arrivals.unconfirmed', 'Arrivals of tomorrow did not confirm', properties: ['count' => $silent->count()]);

        try {
            $this->alerts->notify(AlertKind::ArrivalUnconfirmed, (string) trans_choice(
                ':count arrival of tomorrow did not confirm: :names. A call avoids a no-show|:count arrivals of tomorrow did not confirm: :names. A call avoids a no-show',
                $silent->count(), ['names' => $names], $hotel->default_locale,
            ));
        } catch (Throwable $e) {
            report($e);
        }

        return $silent->count();
    }

    /**
     * Today's arrivals by answer, for the morning summary.
     *
     * @return array{confirmed: int, waiting: list<string>, declined: int, etas: list<array{name: string, slot: string}>}
     */
    public function todaySummary(): array
    {
        $checks = ArrivalCheck::query()->with('booking.guest')
            ->whereNotNull('card_sent_at')
            ->whereHas('booking', fn ($q) => $q->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
                ->where('check_in', HotelClock::today($this->context->hotel())->toDateString()))
            ->get();

        $out = ['confirmed' => 0, 'waiting' => [], 'declined' => 0, 'etas' => []];
        foreach ($checks as $check) {
            match ($check->state()) {
                'confirmed' => $out['confirmed']++,
                'declined' => $out['declined']++,
                default => $out['waiting'][] = (string) $check->booking->guest?->name(),
            };
            if ($check->eta_slot !== null) {
                $out['etas'][] = ['name' => (string) $check->booking->guest?->first_name, 'slot' => $check->eta_slot];
            }
        }

        return $out;
    }
}
