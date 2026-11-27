<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Models\Conversation;
use Illuminate\Database\Eloquent\Collection;

/**
 * 0.39.2: the bookings that belong to the guest writing on WhatsApp, for
 * every assistant tool. A booking is the guest's when it is on the guest
 * linked to this WhatsApp number OR on any guest with the same phone
 * number: a booking made at reception, or approved from a request, can sit
 * on another guest record with the same number. Bookings of other numbers
 * are never returned, even when the guest knows their reference.
 */
final class GuestBookings
{
    /**
     * @param  list<BookingStatus>  $statuses
     * @return Collection<int, Booking>
     */
    public function forConversation(?Conversation $conversation, array $statuses, bool $upcomingOnly = true): Collection
    {
        $guestId = $conversation?->contact?->guest_id;
        $phone = GuestDirectory::normalisePhone($conversation?->contact?->wa_id === null ? null : '+'.ltrim((string) $conversation->contact->wa_id, '+'));

        if ($guestId === null && $phone === null) {
            return new Collection;
        }

        return Booking::query()->with(['category', 'guest'])
            ->where(fn ($q) => $q
                ->when($guestId !== null, fn ($q) => $q->orWhere('guest_id', $guestId))
                ->when($phone !== null, fn ($q) => $q->orWhereHas('guest', fn ($g) => $g->where('phone_e164', $phone))))
            ->whereIn('status', array_map(fn (BookingStatus $s): string => $s->value, $statuses))
            ->when($upcomingOnly, fn ($q) => $q->whereDate('check_out', '>=', now()->toDateString()))
            ->orderBy('check_in')
            ->get();
    }
}
