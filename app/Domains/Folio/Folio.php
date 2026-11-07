<?php

declare(strict_types=1);

namespace App\Domains\Folio;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\EventQuote;
use App\Domains\Finance\Taxes\TaxCalculator;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Finance\Taxes\TaxScope;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\OrderPayment;
use App\Domains\Orders\OrderStatus;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * A stay's bill: the nights (always the booking's current total), orders
 * delivered to the room bill, extra charges, corrections and payments.
 */
final class Folio
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TaxCalculator $taxes,
    ) {}

    /**
     * @return array{lines: list<array{id: int|null, type: string, description: string, quantity: int, amount_minor: int, tax_minor: int, method: string|null, reference: string|null, reason: string|null, by: string|null, at: string|null, corrected: bool, is_correction: bool}>, charges_minor: int, payments_minor: int, balance_minor: int}
     */
    public function statement(Booking|Event $owner): array
    {
        $hotel = $this->context->hotel();
        $lines = $owner instanceof Booking ? $this->accommodation($owner) : [];

        $stored = FolioLine::query()->with('author')->where($this->column($owner), $owner->id)->orderBy('created_at')->orderBy('id')->get();
        $corrected = $stored->pluck('corrects_line_id')->filter()->all();

        foreach ($stored as $line) {
            $lines[] = [
                'id' => $line->id,
                'type' => $line->type,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'amount_minor' => $line->amount_minor,
                'tax_minor' => $line->tax_minor,
                'method' => $line->method,
                'reference' => $line->reference,
                'reason' => $line->reason,
                'by' => $line->author?->name,
                'at' => $line->created_at?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
                'corrected' => in_array($line->id, $corrected, true),
                'is_correction' => $line->corrects_line_id !== null,
            ];
        }

        $charges = 0;
        $payments = 0;
        foreach ($lines as $line) {
            if ($line['type'] === 'payment') {
                $payments += $line['amount_minor'];
            } else {
                $charges += $line['amount_minor'];
            }
        }

        return ['lines' => $lines, 'charges_minor' => $charges, 'payments_minor' => $payments, 'balance_minor' => $charges - $payments];
    }

    public function payments(Booking|Event $owner): int
    {
        return (int) FolioLine::query()->where($this->column($owner), $owner->id)->where('type', 'payment')->sum('amount_minor');
    }

    /**
     * Post an accepted event quote to the event's account (once).
     */
    public function postQuote(Event $event, EventQuote $quote): ?FolioLine
    {
        try {
            return FolioLine::query()->create([
                'event_id' => $event->id, 'type' => 'charge', 'source' => 'quote', 'source_id' => (string) $quote->id,
                'description' => (string) __('Quote V:version', ['version' => $quote->version]),
                'amount_minor' => $quote->total_minor, 'tax_minor' => $quote->tax_minor, 'reference' => $event->reference,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function column(Booking|Event $owner): string
    {
        return $owner instanceof Booking ? 'booking_id' : 'event_id';
    }

    /**
     * @return list<array{id: int|null, type: string, description: string, quantity: int, amount_minor: int, tax_minor: int, method: string|null, reference: string|null, reason: string|null, by: string|null, at: string|null, corrected: bool, is_correction: bool}>
     */
    private function accommodation(Booking $booking): array
    {
        $nights = (int) $booking->check_in->diffInDays($booking->check_out);

        return [[
            'id' => null,
            'type' => 'charge',
            'description' => __('Accommodation: :nights night(s)', ['nights' => $nights]),
            'quantity' => 1,
            'amount_minor' => $booking->total_minor,
            'tax_minor' => 0,
            'method' => null,
            'reference' => $booking->reference,
            'reason' => null,
            'by' => null,
            'at' => $booking->check_in->toDateString(),
            'corrected' => false,
            'is_correction' => false,
        ]];
    }

    public function balance(Booking|Event $owner): int
    {
        return $this->statement($owner)['balance_minor'];
    }

    /**
     * An extra charge (minibar, laundry…), with the taxes of a scope added
     * as the hotel set them up.
     */
    public function charge(Booking|Event $owner, string $description, int $quantity, Money $unitPrice, ?TaxScope $scope, User $by): FolioLine
    {
        if ($quantity < 1 || $unitPrice->isNegativeOrZero()) {
            throw new InvalidArgumentException('A charge needs a quantity and a positive price.');
        }

        $net = $unitPrice->multipliedBy($quantity);
        $total = $net;
        $tax = Money::zero($net->getCurrency());

        if ($scope !== null) {
            $breakdown = $this->taxes->calculate(TaxRate::query()->where('active', true)->get(), $net, $scope, CarbonImmutable::now($this->context->hotel()->timezone));
            $total = $breakdown->total;
            $tax = $breakdown->totalTax();
        }

        $line = FolioLine::query()->create([
            $this->column($owner) => $owner->id, 'type' => 'charge', 'source' => 'manual',
            'description' => $description, 'quantity' => $quantity,
            'amount_minor' => $total->getMinorAmount()->toInt(), 'tax_minor' => $tax->getMinorAmount()->toInt(),
            'created_by' => $by->getKey(),
        ]);

        Audit::record('folio.charge', 'Charge added to the room bill', subject: $owner, causer: $by, properties: ['line' => $line->id, 'description' => $description, 'amount_minor' => $line->amount_minor]);

        return $line;
    }

    public function pay(Booking|Event $owner, Money $amount, string $method, ?string $reference, User $by): FolioLine
    {
        if ($amount->isNegativeOrZero()) {
            throw new InvalidArgumentException('A payment must be positive.');
        }

        $line = FolioLine::query()->create([
            $this->column($owner) => $owner->id, 'type' => 'payment', 'source' => 'manual',
            'description' => (string) __('Payment: :method', ['method' => RestaurantSettings::methodLabel($method)]),
            'amount_minor' => $amount->getMinorAmount()->toInt(), 'method' => $method, 'reference' => $reference,
            'created_by' => $by->getKey(),
        ]);

        Audit::record('folio.payment', 'Payment recorded on the room bill', subject: $owner, causer: $by, properties: ['line' => $line->id, 'method' => $method, 'amount_minor' => $line->amount_minor, 'reference' => $reference]);

        return $line;
    }

    /**
     * Cancel a line with a line of the opposite amount (nothing is deleted).
     */
    public function correct(FolioLine $line, string $reason, User $by): FolioLine
    {
        if ($line->type === 'correction' || FolioLine::query()->where('corrects_line_id', $line->id)->exists()) {
            throw new InvalidArgumentException('This line has already been corrected.');
        }

        $correction = FolioLine::query()->create([
            'booking_id' => $line->booking_id,
            'event_id' => $line->event_id,
            'type' => $line->type === 'payment' ? 'payment' : 'correction',
            'source' => 'manual',
            'description' => (string) __('Correction: :description', ['description' => $line->description]),
            'quantity' => $line->quantity,
            'amount_minor' => -$line->amount_minor,
            'tax_minor' => -$line->tax_minor,
            'reason' => $reason,
            'corrects_line_id' => $line->id,
            'created_by' => $by->getKey(),
        ]);

        Audit::record('folio.correction', 'Room bill line corrected', subject: $line->booking_id !== null ? Booking::query()->find($line->booking_id) : Event::query()->find($line->event_id), causer: $by, properties: ['line' => $line->id, 'reason' => $reason, 'amount_minor' => $correction->amount_minor]);

        return $correction;
    }

    /**
     * A delivered order charged to the room goes on the bill once.
     */
    public function postOrder(Order $order): ?FolioLine
    {
        if ($order->status !== OrderStatus::Delivered || $order->payment !== OrderPayment::RoomCharge || $order->booking_id === null) {
            return null;
        }

        try {
            return FolioLine::query()->create([
                'booking_id' => $order->booking_id, 'type' => 'charge', 'source' => 'order', 'source_id' => $order->id,
                'description' => (string) __('Order :reference', ['reference' => $order->reference]),
                'amount_minor' => $order->total_minor, 'tax_minor' => $order->tax_minor, 'reference' => $order->reference,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Food and drink already put on the room bill, plus room-charge orders
     * not yet delivered (they count against the stay's limit).
     */
    public function roomChargesUsed(Booking $booking): int
    {
        $posted = (int) FolioLine::query()->where('booking_id', $booking->id)->where('source', 'order')->sum('amount_minor');
        $open = (int) Order::query()->where('booking_id', $booking->id)->where('payment', OrderPayment::RoomCharge->value)
            ->whereIn('status', array_map(fn (OrderStatus $s): string => $s->value, OrderStatus::open()))->sum('total_minor');

        return $posted + $open;
    }
}
