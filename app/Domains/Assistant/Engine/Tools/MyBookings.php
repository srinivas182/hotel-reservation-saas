<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\GuestBookings;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\Folio;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * 0.39.2: the guest's own bookings (testers: "give me my booking details",
 * "the reference of my December booking", "what deposit do I still owe").
 * Only bookings of this WhatsApp number; a reference from another number
 * is answered as not found.
 */
final class MyBookings extends AssistantTool
{
    public function name(): string
    {
        return 'my_bookings';
    }

    public function description(): string
    {
        return 'The guest\'s own bookings found from this WhatsApp number: reference, status, room, dates, guests, total, what is paid, what is still to pay, and the payment link when one is open. Call it whenever the guest asks about their booking, its reference, dates, total, deposit or what they still owe. Give a booking_reference to show one booking.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'booking_reference' => $schema->string()->description('A booking reference the guest gave (e.g. CTH-26-0009), to show only that booking'),
        ];
    }

    protected function run(array $arguments): array
    {
        if ($this->context->isPractice()) {
            return ['practice' => true, 'bookings' => [], 'note' => 'Practice screen: no real guest, so no bookings.'];
        }

        $conversation = Conversation::query()->with('contact')->find($this->context->conversationId);
        $bookings = app(GuestBookings::class)->forConversation($conversation, [
            BookingStatus::Tentative, BookingStatus::Confirmed, BookingStatus::CheckedIn,
        ]);

        $reference = strtoupper(trim((string) ($arguments['booking_reference'] ?? '')));
        if ($reference !== '') {
            $bookings = $bookings->where('reference', $reference)->values();

            if ($bookings->isEmpty()) {
                return ['found' => false, 'next_step' => 'No upcoming booking '.$reference.' belongs to this WhatsApp number. Say so plainly, ask the guest to check the reference, and offer to pass the question to reception. Never invent booking details.'];
            }
        }

        if ($bookings->isEmpty()) {
            return ['found' => false, 'next_step' => 'This WhatsApp number has no upcoming booking. If the guest booked with another number or name, ask for the reference and offer to pass the question to reception.'];
        }

        return [
            'found' => true,
            'bookings' => $bookings->map(fn (Booking $b): array => $this->describe($b))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Booking $booking): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $locale = $this->context->locale !== '' ? $this->context->locale : $hotel->default_locale;
        $format = function (int $minor) use ($booking, $locale): string {
            $money = Money::ofMinor($minor, $booking->currency);
            $this->context->allowMoney($money->abs());

            return MoneyFormatter::format($money, $locale);
        };

        $balance = app(Folio::class)->balance($booking);
        $link = DepositLink::query()->where('booking_id', $booking->id)->where('status', 'open')->latest('created_at')->first();

        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'room' => (string) $booking->category?->localizedName($locale, $hotel->default_locale),
            'rooms' => $booking->rooms,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'adults' => $booking->adults,
            'children' => count(array_filter($booking->children_ages, fn ($a): bool => $a !== null)),
            'breakfast' => $booking->breakfast,
            'total' => $format($booking->total_minor),
            'still_to_pay' => $format(max(0, $balance)),
            'deposit_to_pay' => $link === null ? null : $format($link->deposit_minor),
            'payment_link' => $link === null ? null : route('deposits.show', $link->token),
            'held_until' => $booking->status === BookingStatus::Tentative ? $booking->tentative_until?->toIso8601String() : null,
        ];
    }
}
