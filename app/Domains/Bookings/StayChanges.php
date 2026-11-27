<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

use App\Domains\Audit\Audit;
use App\Domains\Availability\Exceptions\NotEnoughRooms;
use App\Domains\Availability\HoldService;
use App\Domains\Bookings\Exceptions\BookingActionNotAllowed;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\Quote\QuoteIssue;
use App\Domains\Rates\Quote\QuoteService;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 0.40.0: a new departure date for a guest who is already checked in.
 *
 * Extending: only the extra nights are priced (at today's rates, for the
 * same room category, guests, breakfast and extra bed) and added to the
 * booking's total, so the price agreed for the nights already booked never
 * changes. The extra nights are booked in the same transaction. The guest
 * keeps their room when it is free for the extra nights; otherwise
 * reception moves them to a room that is free for the whole new stay.
 *
 * Leaving early: the nights not stayed go back on sale and come off the
 * bill, in proportion to their price. The hotel decides on the Rates page
 * whether one of those nights is still charged (default: none). The first
 * night always counts.
 */
final class StayChanges
{
    public const CHARGE_NONE = 'none';

    public const CHARGE_ONE_NIGHT = 'one_night';

    /** The longest extension reception can give in one step. */
    public const MAX_EXTRA_NIGHTS = 90;

    /** An extension is not a new arrival: these rules do not apply to it. */
    private const ARRIVAL_ONLY_ISSUES = ['min_stay', 'closed_to_arrival', 'kept_for_reception'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly HoldService $holds,
        private readonly QuoteService $quotes,
        private readonly RoomAssignments $assignments,
    ) {}

    /**
     * What the new departure date would mean, without changing anything.
     *
     * @return array{
     *     kind: 'extend'|'shorten'|'same',
     *     possible: bool,
     *     problems: list<string>,
     *     check_out: string,
     *     nights: int,
     *     nights_changed: int,
     *     fee_nights: int,
     *     current_total_minor: int,
     *     new_total_minor: int,
     *     difference_minor: int,
     *     room_conflicts: list<array{position: int, number: string, free: list<array{id: string, number: string}>}>
     * }
     */
    public function preview(Booking $booking, CarbonImmutable $newCheckOut): array
    {
        $newCheckOut = $newCheckOut->startOfDay();
        $problems = $this->dateProblems($booking, $newCheckOut);
        $kind = match (true) {
            $newCheckOut->gt($booking->check_out) => 'extend',
            $newCheckOut->lt($booking->check_out) => 'shorten',
            default => 'same',
        };
        $newTotal = $booking->total_minor;
        $feeNights = 0;
        $conflicts = [];

        if ($problems === [] && $kind === 'extend') {
            $quote = $this->quotes->quote($this->extraStay($booking, $newCheckOut));
            foreach ($quote->issues as $issue) {
                if (! in_array($issue->code, self::ARRIVAL_ONLY_ISSUES, true)) {
                    $problems[] = $issue->message;
                }
            }
            $newTotal = $booking->total_minor + $quote->total->getMinorAmount()->toInt();
            $conflicts = $this->roomConflicts($booking, $newCheckOut);
        } elseif ($problems === [] && $kind === 'shorten') {
            ['total_minor' => $newTotal, 'fee_nights' => $feeNights] = $this->shortenedTotal($booking, $newCheckOut);
        }

        return [
            'kind' => $kind,
            'possible' => $problems === [] && $kind !== 'same',
            'problems' => $problems,
            'check_out' => $newCheckOut->toDateString(),
            'nights' => (int) $booking->check_in->diffInDays($newCheckOut),
            'nights_changed' => (int) abs($booking->check_out->diffInDays($newCheckOut)),
            'fee_nights' => $feeNights,
            'current_total_minor' => $booking->total_minor,
            'new_total_minor' => $newTotal,
            'difference_minor' => $newTotal - $booking->total_minor,
            'room_conflicts' => $conflicts,
        ];
    }

    /**
     * Give the checked-in guest a new departure date.
     *
     * @param  array<int|string, string>  $roomsByPosition  rooms to move the guest to (position => room ID), when their room is taken on the extra nights
     */
    public function change(Booking $booking, CarbonImmutable $newCheckOut, ?User $by = null, array $roomsByPosition = []): void
    {
        $newCheckOut = $newCheckOut->startOfDay();
        $problems = $this->dateProblems($booking, $newCheckOut);

        if ($problems !== []) {
            throw new BookingActionNotAllowed($problems[0]);
        }

        if ($newCheckOut->isSameDay($booking->check_out)) {
            throw new BookingActionNotAllowed(__('This is already the departure date.'));
        }

        $newCheckOut->gt($booking->check_out)
            ? $this->extend($booking, $newCheckOut, $by, $roomsByPosition)
            : $this->shorten($booking, $newCheckOut, $by);
    }

    /**
     * The total when the stay ends on $newCheckOut instead of the booked
     * departure: the nights not stayed come off in proportion to their price,
     * except the one the hotel still charges (Rates page setting).
     *
     * @return array{total_minor: int, fee_nights: int}
     */
    public function shortenedTotal(Booking $booking, CarbonImmutable $newCheckOut): array
    {
        $removed = (int) $newCheckOut->diffInDays($booking->check_out);

        if ($removed <= 0) {
            return ['total_minor' => $booking->total_minor, 'fee_nights' => 0];
        }

        $feeNights = HotelSettings::current()->early_departure_charge === self::CHARGE_ONE_NIGHT ? 1 : 0;
        $freeFrom = $newCheckOut->addDays($feeNights);

        if ($freeFrom->gte($booking->check_out)) {
            return ['total_minor' => $booking->total_minor, 'fee_nights' => $feeNights];
        }

        // Each night's share of the total: by its price when the booking kept
        // the nightly prices, otherwise every night the same.
        $nights = $this->nightRates($booking);
        $all = array_sum($nights);
        $refunded = array_sum(array_filter($nights, fn (int $rate, string $date): bool => $date >= $freeFrom->toDateString(), ARRAY_FILTER_USE_BOTH));
        $reduction = $all > 0 ? intdiv($booking->total_minor * $refunded + intdiv($all, 2), $all) : 0;

        return ['total_minor' => max(0, $booking->total_minor - $reduction), 'fee_nights' => $feeNights];
    }

    /**
     * @param  array<int|string, string>  $roomsByPosition
     */
    private function extend(Booking $booking, CarbonImmutable $newCheckOut, ?User $by, array $roomsByPosition): void
    {
        $extra = $this->extraStay($booking, $newCheckOut);
        $quote = $this->quotes->quote($extra);
        $issues = array_values(array_filter($quote->issues, fn (QuoteIssue $issue): bool => ! in_array($issue->code, [...self::ARRIVAL_ONLY_ISSUES, 'not_available'], true)));

        if ($issues !== []) {
            throw new BookingActionNotAllowed($issues[0]->message);
        }

        $category = RoomCategory::query()->findOrFail($booking->room_category_id);
        $oldCheckOut = $booking->check_out;
        $oldTotal = $booking->total_minor;
        $added = $quote->total->getMinorAmount()->toInt();
        $moves = [];

        try {
            DB::transaction(function () use ($booking, $category, $oldCheckOut, $newCheckOut, $quote, $added, $by, $roomsByPosition, &$moves): void {
                // Under lock: the extra nights must still be free.
                $hold = $this->holds->place($category, $oldCheckOut, $newCheckOut, $booking->rooms, sourceType: 'booking', sourceId: $booking->id, by: $by, minutes: 10);
                $this->holds->convert($hold);

                $snapshot = $booking->price_snapshot;
                $snapshot['nights'] = [
                    ...(array) ($snapshot['nights'] ?? []),
                    ...array_map(fn (array $night): array => [
                        'date' => $night['date'],
                        'rate_minor' => $night['rate']->getMinorAmount()->toInt(),
                        'label' => $night['label'],
                    ], $quote->nights),
                ];
                $snapshot['changes'] = [...(array) ($snapshot['changes'] ?? []), [
                    'kind' => 'extended', 'from' => $oldCheckOut->toDateString(), 'to' => $newCheckOut->toDateString(),
                    'amount_minor' => $added, 'at' => now()->toIso8601String(),
                ]];

                $booking->forceFill([
                    'check_out' => $newCheckOut,
                    'total_minor' => $booking->total_minor + $added,
                    'price_snapshot' => $snapshot,
                ])->save();

                $moves = $this->keepRooms($booking, $roomsByPosition);
            });
        } catch (NotEnoughRooms) {
            $booking->refresh();

            throw new BookingActionNotAllowed(__('Not enough rooms are free for the extra nights.'));
        } catch (Throwable $exception) {
            // The transaction was rolled back: so is the booking in memory.
            $booking->refresh();

            throw $exception;
        }

        Audit::record('bookings.extended', 'Stay extended', subject: $booking, causer: $by, properties: [
            'reference' => $booking->reference,
            'old' => ['check_out' => $oldCheckOut->toDateString(), 'total_minor' => $oldTotal],
            'new' => ['check_out' => $newCheckOut->toDateString(), 'total_minor' => $booking->total_minor],
            'room_moves' => $moves,
        ]);
    }

    private function shorten(Booking $booking, CarbonImmutable $newCheckOut, ?User $by): void
    {
        $oldCheckOut = $booking->check_out;
        $oldTotal = $booking->total_minor;

        DB::transaction(function () use ($booking, $newCheckOut, $oldCheckOut): void {
            ['total_minor' => $total, 'fee_nights' => $feeNights] = $this->shortenedTotal($booking, $newCheckOut);
            $this->holds->unbook($booking->room_category_id, $newCheckOut, $oldCheckOut, $booking->rooms);

            $booking->forceFill([
                'check_out' => $newCheckOut,
                'total_minor' => $total,
                'price_snapshot' => $this->shortenedSnapshot($booking, $newCheckOut, $total, $feeNights),
            ])->save();
        });

        Audit::record('bookings.shortened', 'Departure brought forward', subject: $booking, causer: $by, properties: [
            'reference' => $booking->reference,
            'old' => ['check_out' => $oldCheckOut->toDateString(), 'total_minor' => $oldTotal],
            'new' => ['check_out' => $newCheckOut->toDateString(), 'total_minor' => $booking->total_minor],
        ]);
    }

    /**
     * The price record after an early departure: the nights not stayed are
     * removed, and the change (with any night still charged) is noted.
     *
     * @return array<string, mixed>
     */
    public function shortenedSnapshot(Booking $booking, CarbonImmutable $newCheckOut, int $newTotal, int $feeNights): array
    {
        $snapshot = $booking->price_snapshot;
        $snapshot['nights'] = array_values(array_filter((array) ($snapshot['nights'] ?? []), fn (array $night): bool => (string) $night['date'] < $newCheckOut->toDateString()));
        $snapshot['changes'] = [...(array) ($snapshot['changes'] ?? []), [
            'kind' => 'shortened', 'from' => $booking->check_out->toDateString(), 'to' => $newCheckOut->toDateString(),
            'amount_minor' => $newTotal - $booking->total_minor, 'fee_nights' => $feeNights, 'at' => now()->toIso8601String(),
        ]];

        return $snapshot;
    }

    /**
     * The guest keeps each room that is free for the new stay; a room that
     * is not is swapped for the one reception chose.
     *
     * @param  array<int|string, string>  $roomsByPosition
     * @return list<array{position: int, from: string, to: string}>
     */
    private function keepRooms(Booking $booking, array $roomsByPosition): array
    {
        $moves = [];
        $lines = BookingRoom::query()->where('booking_id', $booking->id)->whereNotNull('room_id')->with('room')->orderBy('position')->get();

        foreach ($lines as $line) {
            Room::query()->whereKey($line->room_id)->lockForUpdate()->first();

            if ($line->room !== null && $this->assignments->canAssign($booking, $line->room)) {
                continue;
            }

            $choice = (string) ($roomsByPosition[$line->position] ?? '');
            $room = $choice === '' ? null : Room::query()->whereKey($choice)->lockForUpdate()->first();
            $usedElsewhere = $room !== null && $lines->contains(fn (BookingRoom $other): bool => $other->position !== $line->position && $other->room_id === $room->id);

            if ($room === null || $usedElsewhere || $room->room_category_id !== $booking->room_category_id || ! $this->assignments->canAssign($booking, $room)) {
                throw new BookingActionNotAllowed(__('Room :number is taken on the extra nights. Choose another room for the guest.', ['number' => (string) $line->room?->number]));
            }

            $moves[] = ['position' => $line->position, 'from' => (string) $line->room?->number, 'to' => $room->number];
            $line->forceFill(['room_id' => $room->id])->save();
        }

        return $moves;
    }

    /**
     * Rooms the guest holds that are taken on the extra nights, with the
     * rooms free for the whole new stay to move them to.
     *
     * @return list<array{position: int, number: string, free: list<array{id: string, number: string}>}>
     */
    private function roomConflicts(Booking $booking, CarbonImmutable $newCheckOut): array
    {
        $eligible = $this->assignments->eligibleRooms($booking, $booking->check_in, $newCheckOut);
        $held = $booking->bookingRooms()->whereNotNull('room_id')->with('room')->orderBy('position')->get();
        $heldIds = $held->pluck('room_id')->all();
        $free = array_values($eligible->reject(fn (Room $room): bool => in_array($room->id, $heldIds, true))
            ->map(fn (Room $room): array => ['id' => $room->id, 'number' => $room->number])->all());
        $conflicts = [];

        foreach ($held as $line) {
            if (! $eligible->contains(fn (Room $room): bool => $room->id === $line->room_id)) {
                $conflicts[] = ['position' => $line->position, 'number' => (string) $line->room?->number, 'free' => $free];
            }
        }

        return $conflicts;
    }

    private function extraStay(Booking $booking, CarbonImmutable $newCheckOut): StayRequest
    {
        return new StayRequest(
            categoryId: $booking->room_category_id,
            checkIn: $booking->check_out,
            checkOut: $newCheckOut,
            adults: $booking->adults,
            childAges: array_values(array_filter($booking->children_ages, fn ($age): bool => $age !== null)),
            rooms: $booking->rooms,
            breakfast: $booking->breakfast,
            extraBed: $booking->extra_bed,
        );
    }

    /**
     * @return list<string>
     */
    private function dateProblems(Booking $booking, CarbonImmutable $newCheckOut): array
    {
        if ($booking->status !== BookingStatus::CheckedIn) {
            return [(string) __('Only the stay of a guest who is checked in can be changed this way.')];
        }

        $today = HotelClock::today($this->context->hotel());
        $earliest = $today->max($booking->check_in->addDay());

        if ($newCheckOut->lt($earliest)) {
            return [(string) __('The departure cannot be before :date.', ['date' => $earliest->toDateString()])];
        }

        if ($newCheckOut->gt($booking->check_out->addDays(self::MAX_EXTRA_NIGHTS))) {
            return [(string) __('A stay can be extended by up to :nights nights at a time.', ['nights' => self::MAX_EXTRA_NIGHTS])];
        }

        return [];
    }

    /**
     * Each booked night's price (date => minor), or 1 per night when the
     * booking has no nightly prices.
     *
     * @return array<string, int>
     */
    private function nightRates(Booking $booking): array
    {
        $rates = [];
        foreach ((array) ($booking->price_snapshot['nights'] ?? []) as $night) {
            if (is_array($night) && isset($night['date'])) {
                $rates[(string) $night['date']] = max(0, (int) ($night['rate_minor'] ?? 0));
            }
        }

        $expected = (int) $booking->check_in->diffInDays($booking->check_out);
        if (count($rates) !== $expected || array_sum($rates) === 0) {
            $rates = [];
            for ($night = $booking->check_in; $night->lt($booking->check_out); $night = $night->addDay()) {
                $rates[$night->toDateString()] = 1;
            }
        }

        return $rates;
    }
}
