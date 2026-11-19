<?php

declare(strict_types=1);

namespace App\Domains\Deposits;

use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;

/**
 * Deposits as the dashboard shows them (Sprint F).
 */
final class DepositPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(DepositLink $link): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $money = fn (?int $minor): ?string => $minor === null ? null : MoneyFormatter::format(Money::ofMinor($minor, $link->currency), $hotel->default_locale);
        $at = fn ($date): ?string => $date?->setTimezone($hotel->timezone)->format('Y-m-d H:i');

        return [
            'id' => $link->id,
            'booking_id' => $link->booking_id,
            'reference' => $link->booking->reference,
            'guest' => $link->booking->guest?->name(),
            'status' => $link->status,
            'deposit' => $money($link->deposit_minor),
            'paid' => $money($link->paid_minor),
            'method' => $link->method === null ? null : RestaurantSettings::methodLabel($link->method),
            'via' => $link->paid_via,
            'receipt' => $link->receipt_number,
            'paid_at' => $at($link->paid_at),
            'confirmed_by' => $link->confirmer?->name,
            'expires_at' => $at($link->expires_at),
            'sent_at' => $at($link->sent_at),
            'reminded_at' => $at($link->reminded_at),
            'refund_status' => $link->refund_status,
            'refund_due' => $money($link->refund_due_minor),
            'refunded_at' => $at($link->refunded_at),
            'refund_note' => $link->refund_note,
        ];
    }

    /**
     * The deposit panel of a booking page, or null when there is nothing to show.
     *
     * @return array<string, mixed>|null
     */
    public static function forBooking(Booking $booking, ?User $user): ?array
    {
        $link = DepositLink::query()->with(['booking.guest', 'confirmer'])->where('booking_id', $booking->id)
            ->orderByRaw("case status when 'paid' then 0 when 'open' then 1 else 2 end")->latest()->first();
        $canPay = $user?->can('folio.payments') === true;
        $tentative = $booking->status === BookingStatus::Tentative;

        if ($link === null && ! ($tentative && $canPay)) {
            return null;
        }

        $deposits = app(Deposits::class);

        return [
            'link' => $link === null ? null : [
                ...self::row($link),
                'url' => $canPay && $link->isOpen() ? $deposits->url($link) : null,
            ],
            'online' => $deposits->online(),
            'can_send' => $canPay && $tentative && $booking->guest?->phone_e164 !== null,
            'can_confirm' => $canPay && $tentative && $link?->status !== 'paid',
            'can_refund' => $canPay && $link?->refund_status === 'due',
            'deposit' => MoneyFormatter::format(Money::ofMinor($link->deposit_minor ?? $deposits->depositOf($booking), $booking->currency), app(TenantContext::class)->hotel()->default_locale),
            'deposit_minor' => $link->deposit_minor ?? $deposits->depositOf($booking),
            'methods' => array_map(fn (string $m): array => ['value' => $m, 'label' => RestaurantSettings::methodLabel($m)], RestaurantSettings::METHODS),
        ];
    }
}
