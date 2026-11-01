<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

use App\Domains\Audit\Audit;
use App\Domains\Availability\Exceptions\NotEnoughRooms;
use App\Domains\Availability\HoldService;
use App\Domains\Bookings\Exceptions\BookingNotPossible;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Finance\Taxes\TaxBreakdown;
use App\Domains\Finance\Taxes\TaxLine;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\Quote\Quote;
use App\Domains\Rates\Quote\QuoteIssue;
use App\Domains\Rates\Quote\QuoteService;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates bookings. A booking is priced by the quote engine, then its
 * rooms are reserved with the S2.3 hold machinery inside the same
 * transaction: confirmed bookings convert the hold to booked rooms at once;
 * tentative bookings keep the hold until their deadline.
 */
final class BookingService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly QuoteService $quotes,
        private readonly HoldService $holds,
        private readonly BookingReferences $references,
        private readonly GuestDirectory $guests,
    ) {}

    public function create(NewBooking $data, ?User $by = null): Booking
    {
        if (! in_array($data->status, [BookingStatus::Confirmed, BookingStatus::Tentative], true)) {
            throw new InvalidArgumentException('New bookings are tentative or confirmed.');
        }

        $quote = $this->quotes->quote($data->stay);

        if (! $quote->bookable()) {
            throw new BookingNotPossible($quote->issues);
        }

        if ($data->overrideTotal !== null && trim((string) $data->overrideReason) === '') {
            throw new InvalidArgumentException('A reason is required to change the price.');
        }

        $hotel = $this->context->hotel();
        $category = RoomCategory::query()->findOrFail($data->stay->categoryId);
        $tentativeHours = HotelSettings::current()->tentative_hours;

        try {
            $booking = DB::transaction(function () use ($data, $quote, $hotel, $category, $tentativeHours, $by): Booking {
                $guest = $this->guests->findOrCreate($data->guest);
                $total = $data->overrideTotal ?? $quote->total;
                $tentative = $data->status === BookingStatus::Tentative;

                $booking = Booking::query()->create([
                    'reference' => $this->references->next(),
                    'guest_id' => $guest->id,
                    'channel' => $data->channel,
                    'external_reference' => $data->externalReference,
                    'group_label' => $data->groupLabel,
                    'status' => $data->status,
                    'room_category_id' => $category->id,
                    'check_in' => $data->stay->checkIn,
                    'check_out' => $data->stay->checkOut,
                    'rooms' => $data->stay->rooms,
                    'adults' => $data->stay->adults,
                    'children_ages' => $data->stay->childAges,
                    'breakfast' => $data->stay->breakfast,
                    'extra_bed' => $data->stay->extraBed,
                    'price_snapshot' => self::snapshot($quote),
                    'total_minor' => $total->getMinorAmount()->toInt(),
                    'currency' => $hotel->currency,
                    'price_overridden' => $data->overrideTotal !== null,
                    'override_reason' => $data->overrideTotal !== null ? trim((string) $data->overrideReason) : null,
                    'payment_note' => $data->paymentNote,
                    'notes' => $data->notes,
                    'tentative_until' => $tentative ? now()->addHours($tentativeHours) : null,
                    'created_by' => $by?->getKey(),
                    'confirmed_at' => $tentative ? null : now(),
                    'confirmed_by' => $tentative ? null : $by?->getKey(),
                ]);

                foreach (range(1, $data->stay->rooms) as $position) {
                    BookingRoom::query()->create(['booking_id' => $booking->id, 'position' => $position]);
                }

                $hold = $this->holds->place(
                    $category,
                    $data->stay->checkIn,
                    $data->stay->checkOut,
                    $data->stay->rooms,
                    sourceType: 'booking',
                    sourceId: $booking->id,
                    by: $by,
                    minutes: $tentative ? $tentativeHours * 60 : 10,
                );

                if (! $tentative) {
                    $this->holds->convert($hold);
                }

                $booking->forceFill(['inventory_hold_id' => $hold->id])->save();

                return $booking;
            });
        } catch (NotEnoughRooms $exception) {
            throw new BookingNotPossible([new QuoteIssue('not_available', $exception->getMessage(), ['available' => $exception->available])]);
        }

        Audit::record('bookings.created', 'Booking created', subject: $booking, causer: $by, properties: [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'channel' => $booking->channel->value,
            'total_minor' => $booking->total_minor,
            'price_overridden' => $booking->price_overridden,
            'override_reason' => $booking->override_reason,
        ]);

        return $booking;
    }

    /**
     * The quote as saved with the booking (amounts in minor units).
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Quote $quote): array
    {
        $minor = fn (Money $money): int => $money->getMinorAmount()->toInt();
        $taxes = fn (?TaxBreakdown $breakdown): array => $breakdown === null ? [] : array_map(fn (TaxLine $line): array => [
            'tax_rate_id' => $line->rate->id,
            'name' => $line->rate->name,
            'amount_minor' => $minor($line->amount),
            'inclusive' => $line->inclusive,
        ], $breakdown->lines);

        return [
            'nights' => array_map(fn (array $night): array => [
                'date' => $night['date'],
                'rate_minor' => $minor($night['rate']),
                'label' => $night['label'],
            ], $quote->nights),
            'lines' => array_map(fn (array $line): array => [
                'code' => $line['code'],
                'label' => $line['label'],
                'quantity' => $line['quantity'],
                'amount_minor' => $minor($line['amount']),
            ], $quote->lines),
            'subtotal_minor' => $minor($quote->subtotal),
            'taxes' => [...$taxes($quote->roomTaxes), ...$taxes($quote->breakfastTaxes)],
            'total_minor' => $minor($quote->total),
            'quoted_at' => now()->toIso8601String(),
        ];
    }
}
