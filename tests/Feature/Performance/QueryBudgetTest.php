<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Conversations\Models\WhatsAppContact;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';

/**
 * A hotel with $n arrivals today and $n WhatsApp conversations.
 *
 * @return array{hotel: Hotel, owner: User, booking: Booking, conversation: Conversation}
 */
function busyHotel(int $n): array
{
    $hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $category = roomCategory($hotel, 'STD', $n + 2);
    rateFor($hotel, $category);
    $booking = null;
    $conversation = null;

    for ($i = 0; $i < $n; $i++) {
        $booking = book($hotel, $category, '2026-11-01', '2026-11-03', phone: '+22507000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));
        $conversation = app(TenantContext::class)->run($hotel, function () use ($i, $booking): Conversation {
            $contact = WhatsAppContact::query()->create(['wa_id' => '22507100'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'guest_id' => $booking->guest_id]);
            $conversation = Conversation::query()->create(['whatsapp_contact_id' => $contact->id, 'status' => 'open', 'last_message_at' => now(), 'unread_count' => 1]);
            foreach (['in', 'out', 'in'] as $k => $direction) {
                Message::query()->create(['conversation_id' => $conversation->id, 'direction' => $direction, 'type' => 'text', 'body' => "Message {$k}", 'status' => $direction === 'in' ? 'received' : 'sent', 'source' => $direction === 'in' ? 'guest' : 'staff', 'sent_at' => now()->subMinutes(10 - $k)]);
            }

            return $conversation;
        });
    }

    return ['hotel' => $hotel, 'owner' => staffMember($hotel, SystemRole::Owner), 'booking' => $booking, 'conversation' => $conversation];
}

function queriesFor(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

beforeEach(function (): void {
    $this->travelTo('2026-11-01 09:00:00');
});

it('keeps the busy hotel screens within their query budget, however much data there is', function (string $route, int $budget): void {
    $small = busyHotel(3);
    $large = busyHotel(25);
    $params = fn (array $h): array => match ($route) {
        'hotel.bookings.show' => [$h['booking']],
        'hotel.inbox.show' => [$h['conversation']],
        default => [],
    };

    $measure = function (array $h) use ($route, $params): int {
        asStaffOf($h['hotel'], $h['owner'])->get(route($route, $params($h)))->assertOk(); // warm up (session, caches)

        return queriesFor(fn () => asStaffOf($h['hotel'], $h['owner'])->get(route($route, $params($h)))->assertOk());
    };

    $few = $measure($small);
    $many = $measure($large);

    // No query per booking or message (N+1), and a fixed ceiling.
    expect($many)->toBeLessThanOrEqual($few + 2, "{$route}: {$few} queries with 3 items, {$many} with 25")
        ->and($many)->toBeLessThanOrEqual($budget, "{$route}: {$many} queries, budget {$budget}");
})->with([
    'today' => ['hotel.today', 60],
    'inbox' => ['hotel.inbox', 60],
    'conversation' => ['hotel.inbox.show', 70],
    'bookings' => ['hotel.bookings.index', 50],
    'booking page' => ['hotel.bookings.show', 70],
    'calendar' => ['hotel.calendar', 50],
    'orders board' => ['hotel.restaurant.orders', 40],
]);

it('keeps the platform home within its budget as hotels grow', function (): void {
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create();
    busyHotel(2);
    Illuminate\Support\Facades\Cache::flush();
    $few = queriesFor(fn () => $this->actingAs($admin)->get(route('platform.home'))->assertOk());

    foreach (range(1, 6) as $i) {
        busyHotel(2);
    }
    Illuminate\Support\Facades\Cache::flush();
    $many = queriesFor(fn () => $this->actingAs($admin)->get(route('platform.home'))->assertOk());

    // The per-hotel health check is cached for a minute; about 25 queries a hotel when it runs.
    expect(($many - $few) / 6)->toBeLessThanOrEqual(30);
});
