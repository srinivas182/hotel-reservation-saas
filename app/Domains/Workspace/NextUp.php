<?php

declare(strict_types=1);

namespace App\Domains\Workspace;

use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\FrontDesk;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single most urgent thing for this person right now (Sprint E,
 * addition 1), among what they are allowed to do, in this order:
 * requests about to expire, guests waiting for an answer, guests who
 * should have left, arrivals without a room, arrivals still expected,
 * shift tasks left. Otherwise "all clear".
 */
final class NextUp
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FrontDesk $desk,
        private readonly ShiftChecklists $checklists,
    ) {}

    /**
     * @param  \Illuminate\Support\Collection<int, Booking>|null  $arrivals  Today's own lists (guest and rooms loaded), when it already has them
     * @param  \Illuminate\Support\Collection<int, Booking>|null  $departures
     * @param  Collection<int, BookingRequest>|null  $requests  pending requests with their guest, any order
     * @return array{kind: string, tone: string, text: string, href: string, more: int}
     */
    public function for(User $user, ?\Illuminate\Support\Collection $arrivals = null, ?\Illuminate\Support\Collection $departures = null, ?Collection $requests = null): array
    {
        $hotel = $this->context->hotel();
        $now = CarbonImmutable::now($hotel->timezone);

        if ($user->can('booking_requests.decide')) {
            $pending = $requests === null
                ? BookingRequest::query()->with('guest')->where('status', BookingRequestStatus::Pending->value)->where('expires_at', '>', now())->orderBy('expires_at')->get()
                : $requests->filter(fn (BookingRequest $r): bool => $r->expires_at->isFuture())->sortBy(fn (BookingRequest $r): int => $r->expires_at->getTimestamp())->values();
            if ($pending->isNotEmpty()) {
                $first = $pending->firstOrFail();

                return $this->item('request', 'urgent', trans_choice(
                    ':count WhatsApp request is waiting: answer :guest before :time.|:count WhatsApp requests are waiting: answer :guest first, before :time.',
                    $pending->count(),
                    ['guest' => (string) $first->guest?->name(), 'time' => (string) $first->expires_at->setTimezone($hotel->timezone)->format('H:i')],
                ), route('hotel.today').'#requests', $pending->count() - 1);
            }
        }

        if ($user->can('conversations.reply')) {
            $waiting = Conversation::query()->with('contact')->where('needs_attention', true)->orderByRaw('coalesce(waiting_since, handed_over_at, last_inbound_at) asc')->get();
            if ($waiting->isNotEmpty()) {
                $first = $waiting->firstOrFail();
                $since = $first->waiting_since ?? $first->handed_over_at ?? $first->last_inbound_at;
                $minutes = $since === null ? 0 : (int) $since->diffInMinutes(now());

                return $this->item('conversation', 'urgent', $minutes >= 1
                    ? (string) __(':guest has been waiting :minutes min for an answer.', ['guest' => (string) ($first->contact->profile_name ?? __('A guest')), 'minutes' => $minutes])
                    : (string) __(':guest is waiting for an answer.', ['guest' => (string) ($first->contact->profile_name ?? __('A guest'))]),
                    route('hotel.inbox.show', $first->id), $waiting->count() - 1);
            }
        }

        if ($user->can('bookings.frontdesk')) {
            $checkout = substr(HotelSettings::current()->check_out_time, 0, 5);
            if ($now->format('H:i') > $checkout) {
                $late = ($departures ?? $this->desk->departures())->filter(fn (Booking $b): bool => $b->status === BookingStatus::CheckedIn);
                if ($late->isNotEmpty()) {
                    $first = $late->firstOrFail();

                    return $this->item('departure', 'normal', trans_choice(
                        ':guest should have left at :time: check them out or extend the stay.|:count guests should have left at :time, starting with :guest.',
                        $late->count(),
                        ['guest' => (string) $first->guest?->name(), 'time' => $checkout],
                    ), route('hotel.today').'#departures', $late->count() - 1);
                }
            }

            $arrivals ??= $this->desk->arrivals();
            $noRoom = $arrivals->filter(fn (Booking $b): bool => $b->bookingRooms->contains(fn (BookingRoom $line): bool => $line->room_id === null) || $b->bookingRooms->isEmpty());
            if ($noRoom->isNotEmpty()) {
                return $this->item('rooms', 'normal', trans_choice(
                    'Choose a room for :guest, arriving today.|Choose rooms for :count arrivals today, starting with :guest.',
                    $noRoom->count(),
                    ['guest' => (string) $noRoom->firstOrFail()->guest?->name()],
                ), route('hotel.today').'#arrivals', $noRoom->count() - 1);
            }

            if ($arrivals->isNotEmpty()) {
                return $this->item('arrivals', 'calm', trans_choice(
                    ':count arrival still expected today: :guest.|:count arrivals still expected today.',
                    $arrivals->count(),
                    ['guest' => (string) $arrivals->firstOrFail()->guest?->name()],
                ), route('hotel.today').'#arrivals', 0);
            }
        }

        foreach ($this->checklists->forDay() as $list) {
            $left = $list['total'] - $list['done'];
            if ($left > 0) {
                return $this->item('checklist', 'calm', trans_choice(':name: :count task left.|:name: :count tasks left.', $left, ['name' => $list['name']]), route('hotel.today').'#checklists', 0);
            }
        }

        return $this->item('clear', 'calm', (string) __('All clear for now. New requests and messages will show here.'), route('hotel.today'), 0);
    }

    /**
     * @return array{kind: string, tone: string, text: string, href: string, more: int}
     */
    private function item(string $kind, string $tone, string $text, string $href, int $more): array
    {
        return ['kind' => $kind, 'tone' => $tone, 'text' => $text, 'href' => $href, 'more' => max(0, $more)];
    }
}
