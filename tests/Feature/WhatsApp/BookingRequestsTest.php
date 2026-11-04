<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\Availability\Models\InventoryDay;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestDecided;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\BookingRequests\Exceptions\RequestNotPossible;
use App\Domains\BookingRequests\Mail\BookingRequestAlertMail;
use App\Domains\Bookings\BookingChannel;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\ToolCall;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Availability/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]])]);
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
    $this->conversation = conversationFor($this, '2250700000001');
});

function conversationFor(object $test, string $waId): Conversation
{
    webhookPost($test, [inbound('wamid.'.$waId, from: $waId, name: 'Awa')]);

    return ($test->in)(fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', $waId))->with('contact.guest')->sole());
}

function submitRequest(object $test, ?Conversation $conversation = null, int $rooms = 1, string $name = 'Awa Koné', string $in = '2026-11-12', string $out = '2026-11-14'): BookingRequest
{
    $conversation ??= $test->conversation;

    return ($test->in)(fn () => app(BookingRequestService::class)->submit(
        $conversation,
        new StayRequest($test->exe->id, CarbonImmutable::parse($in), CarbonImmutable::parse($out), adults: 2 * $rooms > 0 ? max(1, $rooms) : 1, rooms: $rooms),
        $name,
    ));
}

it('lets the assistant send a booking request that holds the rooms', function (): void {
    HotelAssistantAgent::fake([
        new ToolCall('c1', 'request_booking', ['guest_name' => 'Awa Koné', 'category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2]),
        aiReply('Merci Awa ! Votre demande pour 50 000 FCFA est transmise à la réception, qui vous confirmera la réservation.', ['intent' => 'booking_request']),
    ]);

    $result = ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Oui je réserve, Awa Koné')], $this->conversation->id));

    $request = ($this->in)(fn () => BookingRequest::query()->sole());
    expect($result->handover)->toBeFalse()
        ->and($request->status)->toBe(BookingRequestStatus::Pending)
        ->and($request->total_minor)->toBe(50000)
        ->and(($this->in)(fn () => $request->guest->name()))->toBe('Awa Koné')
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-13'))->toBe(['2026-11-12' => 2, '2026-11-13' => 2]);

    Mail::assertQueued(BookingRequestAlertMail::class, fn (BookingRequestAlertMail $m): bool => $m->kind === 'new' && $m->hasTo($this->reception->email));
});

it('creates nothing from the practice screen', function (): void {
    HotelAssistantAgent::fake([
        new ToolCall('c1', 'request_booking', ['guest_name' => 'Awa Koné', 'category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2]),
        aiReply('Demande transmise à la réception.', ['intent' => 'booking_request']),
    ]);

    $result = ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Je réserve')]));

    expect(($this->in)(fn () => BookingRequest::query()->count()))->toBe(0)
        ->and($result->tools[0]['result'])->toContain('"practice":true');
});

it('refuses requests without a name or for stays that cannot be booked', function (): void {
    expect(fn () => submitRequest($this, name: 'A'))->toThrow(fn (RequestNotPossible $e) => expect($e->reason)->toBe('name_missing'))
        ->and(fn () => submitRequest($this, in: '2026-10-01', out: '2026-10-02'))->toThrow(fn (RequestNotPossible $e) => expect($e->reason)->toBe('not_bookable'));
});

it('keeps one open request per conversation, releasing the replaced hold', function (): void {
    $first = submitRequest($this);
    $second = submitRequest($this, in: '2026-11-20', out: '2026-11-21');

    expect(($this->in)(fn () => $first->fresh()->status))->toBe(BookingRequestStatus::Withdrawn)
        ->and(($this->in)(fn () => BookingRequest::query()->where('status', 'pending')->sole()->id))->toBe($second->id)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-12'))->toBe(['2026-11-12' => 3]);
});

it('never promises the last room twice', function (): void {
    submitRequest($this, rooms: 2);
    $other = conversationFor($this, '2250700000002');
    submitRequest($this, $other);
    $third = conversationFor($this, '2250700000003');

    expect(fn () => submitRequest($this, $third))->toThrow(fn (RequestNotPossible $e) => expect($e->reason)->toBe('not_available'));
});

it('holds rooms until an hour after reception next opens', function (): void {
    ($this->in)(fn () => HotelSettings::current()->update(['staffed_hours' => ['sun' => [['from' => '08:00', 'to' => '20:00']], 'mon' => [['from' => '08:00', 'to' => '20:00']]]]));

    expect(submitRequest($this)->expires_at->toDateTimeString())->toBe('2026-11-01 12:00:00');

    $this->travelTo('2026-11-01 22:30:00');
    expect(submitRequest($this, in: '2026-11-20', out: '2026-11-21')->expires_at->toDateTimeString())->toBe('2026-11-02 09:00:00');
});

it('approves a request into a confirmed WhatsApp booking made from the held rooms', function (): void {
    Event::fake([BookingRequestDecided::class]);
    $request = submitRequest($this);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false])->assertSessionHasNoErrors();

    $request = ($this->in)(fn () => $request->fresh());
    $booking = ($this->in)(fn () => Booking::query()->sole());

    expect($request->status)->toBe(BookingRequestStatus::Approved)
        ->and($request->booking_id)->toBe($booking->id)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->channel)->toBe(BookingChannel::WhatsApp)
        ->and($booking->total_minor)->toBe(50000)
        ->and(($this->in)(fn () => (int) InventoryDay::query()->sum('booked')))->toBe(2)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-13'))->toBe(['2026-11-12' => 2, '2026-11-13' => 2]);

    Event::assertDispatched(BookingRequestDecided::class);
});

it('asks reception to confirm a price that changed since the guest was quoted', function (): void {
    $request = submitRequest($this);
    ($this->in)(fn () => CategoryRate::query()->update(['base_minor' => 30000]));

    asStaffOf($this->hotel, $this->reception)->get(route('hotel.today'))->assertInertia(fn ($page) => $page->where('requests.0.price_changed', true));
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false])->assertSessionHasErrors('acknowledge_price');
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => true])->assertSessionHasNoErrors();

    expect(($this->in)(fn () => Booking::query()->sole()->total_minor))->toBe(60000);
});

it('approves with changes, and only managers may change the price', function (): void {
    $request = submitRequest($this);
    $payload = ['edit' => true, 'acknowledge_price' => true, 'room_category_id' => $this->exe->id, 'check_in' => '2026-11-12', 'check_out' => '2026-11-15', 'adults' => 2, 'children' => '', 'rooms' => 1, 'breakfast' => false, 'extra_bed' => false];

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), [...$payload, 'override_total' => '60000', 'override_reason' => 'Fidèle'])->assertForbidden();
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), $payload)->assertSessionHasNoErrors();

    $booking = ($this->in)(fn () => Booking::query()->sole());
    expect($booking->check_out->toDateString())->toBe('2026-11-15')->and($booking->total_minor)->toBe(75000);
});

it('declines a request and releases its rooms', function (): void {
    Event::fake([BookingRequestDecided::class]);
    $request = submitRequest($this);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.decline', $request), ['reason' => 'other'])->assertSessionHasErrors('note');
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.decline', $request), ['reason' => 'full'])->assertSessionHasNoErrors();

    expect(($this->in)(fn () => $request->fresh()->status))->toBe(BookingRequestStatus::Declined)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-12'))->toBe(['2026-11-12' => 3]);
    Event::assertDispatched(BookingRequestDecided::class);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false])->assertSessionHasErrors('request');
});

it('expires unanswered requests, releases the rooms and alerts staff', function (): void {
    $request = submitRequest($this);
    $this->travel(121)->minutes();

    $this->artisan('booking-requests:expire')->assertSuccessful();

    expect(($this->in)(fn () => $request->fresh()->status))->toBe(BookingRequestStatus::Expired)
        ->and(($this->in)(fn () => $this->conversation->fresh()->needs_attention))->toBeTrue()
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-12'))->toBe(['2026-11-12' => 3]);
    Mail::assertQueued(BookingRequestAlertMail::class, fn (BookingRequestAlertMail $m): bool => $m->kind === 'expired');
});

it('shows requests to reception and keeps them within the hotel', function (): void {
    $request = submitRequest($this);

    asStaffOf($this->hotel, $this->reception)->get(route('hotel.today'))->assertInertia(fn ($page) => $page->has('requests', 1)->where('requests.0.reference', $request->reference));
    asStaffOf($this->hotel, $this->reception)->get(route('hotel.inbox.show', $this->conversation))->assertInertia(fn ($page) => $page->has('requests', 1));

    $readOnly = staffMember($this->hotel, SystemRole::ReadOnly);
    asStaffOf($this->hotel, $readOnly)->get(route('hotel.today'))->assertInertia(fn ($page) => $page->has('requests', 0));
    asStaffOf($this->hotel, $readOnly)->post(route('hotel.booking-requests.decline', $request), ['reason' => 'full'])->assertForbidden();

    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->post(route('hotel.booking-requests.decline', $request), ['reason' => 'full'])->assertNotFound();
});
