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
require_once __DIR__.'/../Bookings/helpers.php';
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

it('counts the requests waiting for an answer on Today in the menu', function (): void {
    asStaffOf($this->hotel, $this->reception)->get(route('hotel.inbox'))->assertInertia(fn ($page) => $page->where('actionCounts.requests', 0));
    $request = submitRequest($this);
    asStaffOf($this->hotel, $this->reception)->get(route('hotel.inbox'))->assertInertia(fn ($page) => $page->where('actionCounts.requests', 1));

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.decline', $request), ['reason' => 'full'])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->reception)->get(route('hotel.inbox'))->assertInertia(fn ($page) => $page->where('actionCounts.requests', 0));
});

it('does not send a second request for the same nights unless the guest wants another room', function (): void {
    submitRequest($this);
    $call = ['guest_name' => 'Awa Koné', 'category_code' => 'EXE', 'check_in' => '2026-11-13', 'check_out' => '2026-11-15', 'adults' => 2];

    HotelAssistantAgent::fake([
        new ToolCall('c1', 'request_booking', $call),
        aiReply('Vous avez déjà une demande pour ces dates : la réception vous répond très vite.', ['intent' => 'booking_request']),
    ]);
    ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Je réserve du 13 au 15')], $this->conversation->id));
    expect(($this->in)(fn () => BookingRequest::query()->count()))->toBe(1);

    HotelAssistantAgent::fake([
        new ToolCall('c2', 'request_booking', [...$call, 'additional_room' => true]),
        aiReply('Merci ! La demande pour la deuxième chambre est transmise à la réception.', ['intent' => 'booking_request']),
    ]);
    ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Oui, une deuxième chambre en plus')], $this->conversation->id));
    expect(($this->in)(fn () => BookingRequest::query()->count()))->toBe(2);
});

it('sends the guest the new details when reception changes or cancels the stay', function (): void {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Illuminate\Support\Str::random(12)]]])]);
    $request = submitRequest($this);
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false])->assertSessionHasNoErrors();
    $booking = ($this->in)(fn () => $request->fresh()->booking);
    ($this->in)(fn () => $this->conversation->fresh()->forceFill(['needs_attention' => true, 'handover_reason' => 'assistant:change booking '.$booking->reference.': new dates'])->save());

    asStaffOf($this->hotel, $this->reception)->get(route('hotel.bookings.show', $booking))->assertInertia(fn ($page) => $page->where('guestOnWhatsApp', true));

    $stay = ['room_category_id' => $this->exe->id, 'check_in' => '2026-11-15', 'check_out' => '2026-11-18', 'adults' => 2, 'children' => '', 'rooms' => 1, 'breakfast' => false, 'extra_bed' => false];
    asStaffOf($this->hotel, $this->reception)->put(route('hotel.bookings.update', $booking), [...$stay, 'notify_guest' => true])->assertSessionHasNoErrors()->assertRedirect();

    $last = fn () => ($this->in)(fn () => App\Domains\Conversations\Models\Message::query()->where('direction', 'out')->where('source', 'system')->latest('id')->value('body'));
    expect($last())->toContain($booking->reference)->toContain('18');
    $conversation = ($this->in)(fn () => $this->conversation->fresh());
    expect($conversation->needs_attention)->toBeFalse()->and($conversation->handover_reason)->toBeNull();

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.bookings.cancel', $booking), ['reason' => 'Guest asked', 'notify_guest' => true])->assertSessionHasNoErrors();
    expect($last())->not->toBe(null)->and(($this->in)(fn () => App\Domains\Conversations\Models\Message::query()->where('source', 'system')->count()))->toBeGreaterThanOrEqual(3);
});

function autoConfirm(object $test, array $settings = []): void
{
    ($test->in)(fn () => App\Domains\Ai\Models\HotelAssistantSettings::current()->forceFill([
        'mode' => App\Domains\Ai\AssistantMode::Automatic, 'auto_confirm' => true, ...$settings,
    ])->save());
}

it('confirms a booking at once when the hotel allows it and rooms are free', function (): void {
    autoConfirm($this);
    HotelAssistantAgent::fake([
        new ToolCall('c1', 'request_booking', ['guest_name' => 'Awa Koné', 'category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2]),
        aiReply('Merci Awa, votre réservation est confirmée : les détails et le lien de paiement arrivent dans le message suivant.', ['intent' => 'booking_request']),
    ]);

    ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Oui je réserve, Awa Koné')], $this->conversation->id));

    $request = ($this->in)(fn () => BookingRequest::query()->with('booking')->sole());
    expect($request->status)->toBe(BookingRequestStatus::Approved)
        ->and($request->decided_by)->toBeNull()
        ->and($request->booking)->not->toBeNull()
        ->and($request->booking->status)->toBe(BookingStatus::Confirmed);
    $sent = ($this->in)(fn () => App\Domains\Conversations\Models\Message::query()->where('direction', 'out')->pluck('body')->implode("\n"));
    expect($sent)->toContain('*'.$request->booking->reference.'*')->toContain('confirmée');
});

it('keeps requests for reception when auto-confirm is off, over the limits, or the assistant is supervised', function (array $settings, int $rooms): void {
    autoConfirm($this, $settings);
    $request = submitRequest($this, rooms: $rooms);

    expect(($this->in)(fn () => app(BookingRequestService::class)->autoConfirm($request)))->toBeNull()
        ->and(($this->in)(fn () => $request->fresh()->status))->toBe(BookingRequestStatus::Pending);
})->with([
    'off' => [['auto_confirm' => false], 1],
    'too many rooms' => [['auto_confirm_max_rooms' => 1], 2],
    'too many nights' => [['auto_confirm_max_nights' => 1], 1],
    'supervised' => [['mode' => App\Domains\Ai\AssistantMode::Supervised], 1],
]);

it('lets a guest cancel their own booking within the free cancellation time, after confirming', function (): void {
    autoConfirm($this);
    ($this->in)(fn () => App\Domains\Ai\Models\HotelAssistantSettings::current()->forceFill(['deposit_refund_days' => 2])->save());
    $booking = ($this->in)(fn () => app(BookingRequestService::class)->autoConfirm(submitRequest($this)));
    $cancel = function (string $id): void {
        HotelAssistantAgent::fake([new ToolCall($id, 'cancel_booking', ['confirm' => true]), aiReply('D’accord.', ['intent' => 'cancellation'])]);
        ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Annulez ma réservation')], $this->conversation->id));
    };

    // A "yes" before the guest was asked is not enough.
    $cancel('x1');
    expect(($this->in)(fn () => $booking->fresh()->status))->toBe(BookingStatus::Confirmed);

    $cancel('x2');
    expect(($this->in)(fn () => $booking->fresh()->status))->toBe(BookingStatus::Cancelled)
        ->and(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-13'))->toBe(['2026-11-12' => 3, '2026-11-13' => 3]);
});

it('sends late or non-refundable cancellations to reception', function (?int $days): void {
    autoConfirm($this);
    ($this->in)(fn () => App\Domains\Ai\Models\HotelAssistantSettings::current()->forceFill(['deposit_refund_days' => $days])->save());
    $booking = ($this->in)(fn () => app(BookingRequestService::class)->autoConfirm(submitRequest($this)));
    $this->travelTo('2026-11-11 09:00:00');

    foreach (['y1', 'y2'] as $id) {
        HotelAssistantAgent::fake([new ToolCall($id, 'cancel_booking', ['confirm' => true]), aiReply('La réception vous contacte.', ['intent' => 'cancellation'])]);
        ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Annulez ma réservation')], $this->conversation->id));
    }

    expect(($this->in)(fn () => $booking->fresh()->status))->toBe(BookingStatus::Confirmed);
})->with(['two days rule, one day before' => [2], 'never refunded' => [null]]);

it('shows the guest their own bookings by WhatsApp number, and never another number\'s', function (): void {
    $mine = book($this->hotel, $this->exe, '2026-12-10', '2026-12-12', phone: '+2250700000001');
    $other = book($this->hotel, $this->exe, '2026-12-15', '2026-12-16', phone: '+2250799999999');
    $tool = fn (array $arguments): array => ($this->in)(fn () => json_decode((string) (new App\Domains\Assistant\Engine\Tools\MyBookings(new App\Domains\Assistant\Engine\ToolContext('fr', $this->conversation->id)))->handle(new Laravel\Ai\Tools\Request($arguments)), true));

    $result = $tool([]);
    expect($result['found'])->toBeTrue()
        ->and(collect($result['bookings'])->pluck('reference')->all())->toBe([$mine->reference]);

    $foreign = $tool(['booking_reference' => $other->reference]);
    expect($foreign['found'])->toBeFalse();
});

it('sends a confirmed change to reception, who apply it from Today, and the guest gets the new details', function (): void {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Illuminate\Support\Str::random(12)]]])]);
    $booking = book($this->hotel, $this->exe, '2026-12-10', '2026-12-12', phone: '+2250700000001');
    ($this->in)(fn () => $this->conversation->forceFill(['last_inbound_at' => now()])->save());

    HotelAssistantAgent::fake([
        new ToolCall('q1', 'quote_booking_change', ['booking_reference' => $booking->reference, 'check_out' => '2026-12-13', 'send_to_reception' => true]),
        aiReply('C’est transmis à la réception, qui vous confirme la modification.', ['intent' => 'modification_or_cancellation']),
    ]);
    ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Oui, ajoutez une nuit')], $this->conversation->id));

    $change = ($this->in)(fn () => App\Domains\BookingRequests\BookingChangeRequest::query()->sole());
    expect($change->kind)->toBe('change')->and($change->status)->toBe('pending')->and($change->stay['check_out'])->toBe('2026-12-13');

    asStaffOf($this->hotel, $this->reception)->get(route('hotel.today'))
        ->assertInertia(fn ($page) => $page->where('changeRequests.0.reference', $booking->reference)->where('actionCounts.requests', 1));

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.change-requests.apply', $change))->assertSessionHasNoErrors();

    expect(($this->in)(fn () => $booking->fresh()->check_out->toDateString()))->toBe('2026-12-13')
        ->and(($this->in)(fn () => $change->fresh()->status))->toBe('applied')
        ->and(($this->in)(fn () => App\Domains\Conversations\Models\Message::query()->where('source', 'system')->latest('id')->value('body')))->toContain($booking->reference);
});

it('lets reception decline a late cancellation, and the guest is told why', function (): void {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Illuminate\Support\Str::random(12)]]])]);
    ($this->in)(fn () => App\Domains\Ai\Models\HotelAssistantSettings::current()->forceFill(['deposit_refund_days' => null])->save());
    $booking = book($this->hotel, $this->exe, '2026-12-10', '2026-12-12', phone: '+2250700000001');
    ($this->in)(fn () => $this->conversation->forceFill(['last_inbound_at' => now()])->save());

    HotelAssistantAgent::fake([
        new ToolCall('c1', 'cancel_booking', ['confirm' => true]),
        aiReply('La réception va examiner votre demande d’annulation.', ['intent' => 'modification_or_cancellation']),
    ]);
    ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Oui, annulez')], $this->conversation->id));

    $change = ($this->in)(fn () => App\Domains\BookingRequests\BookingChangeRequest::query()->sole());
    expect($change->kind)->toBe('cancellation');

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.change-requests.decline', $change), ['reason' => 'la nuit est due'])->assertSessionHasNoErrors();

    expect(($this->in)(fn () => $booking->fresh()->status))->toBe(BookingStatus::Confirmed)
        ->and(($this->in)(fn () => App\Domains\Conversations\Models\Message::query()->where('source', 'system')->latest('id')->value('body')))->toContain('la nuit est due');

    // Already handled: a second click changes nothing.
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.change-requests.apply', $change))->assertSessionHasErrors('change');
});
