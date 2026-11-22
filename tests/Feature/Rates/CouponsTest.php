<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\BookingRequests\Exceptions\RequestNotPossible;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Rates\Models\Coupon;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\ToolCall;

require_once __DIR__.'/../WhatsApp/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
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
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
    webhookPost($this, [inbound('wamid.C1', from: '2250700000001', name: 'Awa')]);
    $this->conversation = ($this->in)(fn () => Conversation::query()->with('contact.guest')->sole());
    $this->coupon = fn (array $attributes = []) => ($this->in)(fn () => tap(new Coupon)->forceFill(['code' => 'WELCOME10', 'kind' => 'percent', 'value' => 10, 'active' => true, ...$attributes])->save());
});

function couponRequest(object $test, ?string $code, string $in = '2026-11-12', string $out = '2026-11-14'): BookingRequest
{
    return ($test->in)(fn () => app(BookingRequestService::class)->submit(
        $test->conversation,
        new StayRequest($test->exe->id, CarbonImmutable::parse($in), CarbonImmutable::parse($out), adults: 2),
        'Awa Koné',
        $code,
    ));
}

it('takes the coupon off the request, keeps it on the booking and counts one use', function (): void {
    ($this->coupon)();
    $plain = ($this->in)(fn () => app(App\Domains\Rates\Quote\QuoteService::class)->quote(new StayRequest($this->exe->id, CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-14'), adults: 2))->total->getMinorAmount()->toInt());

    $request = couponRequest($this, ' welcome10 ');
    expect($request->coupon_code)->toBe('WELCOME10')
        ->and($request->discount_minor)->toBe(intdiv($plain, 10))
        ->and($request->total_minor)->toBe($plain - intdiv($plain, 10));

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false])->assertSessionHasNoErrors();

    $booking = ($this->in)(fn () => Booking::query()->sole());
    expect($booking->total_minor)->toBe($request->total_minor)
        ->and($booking->coupon_code)->toBe('WELCOME10')
        ->and($booking->coupon_discount_minor)->toBe(intdiv($plain, 10))
        ->and(($this->in)(fn () => Coupon::query()->sole()->used_count))->toBe(1);
});

it('refuses codes that are unknown, expired, used up, too short a stay or switched off', function (array $attributes, string $code, string $reason): void {
    ($this->coupon)($attributes);

    expect(fn () => couponRequest($this, $code))->toThrow(RequestNotPossible::class, $reason);
    expect(($this->in)(fn () => BookingRequest::query()->count()))->toBe(0);
})->with([
    'unknown' => [[], 'NOPE', 'This code is not valid.'],
    'expired' => [['valid_until' => '2026-10-31'], 'WELCOME10', 'This code has expired.'],
    'not yet' => [['valid_from' => '2026-12-01'], 'WELCOME10', 'This code is not valid yet.'],
    'used up' => [['max_uses' => 2, 'used_count' => 2], 'WELCOME10', 'maximum number of times'],
    'short stay' => [['min_nights' => 3], 'WELCOME10', 'at least 3 nights'],
    'off' => [['active' => false], 'WELCOME10', 'This code is not valid.'],
]);

it('caps a fixed discount at the price of the stay', function (): void {
    ($this->coupon)(['code' => 'BIG', 'kind' => 'fixed', 'value' => 10_000_000]);

    expect(couponRequest($this, 'BIG')->total_minor)->toBe(0);
});

it('lets the assistant pass the code the guest gave', function (): void {
    ($this->coupon)();
    HotelAssistantAgent::fake([
        new ToolCall('c1', 'request_booking', ['guest_name' => 'Awa Koné', 'category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2, 'coupon_code' => 'WELCOME10']),
        aiReply('Merci Awa, votre demande est transmise à la réception.', ['intent' => 'booking_request']),
    ]);

    ($this->in)(fn () => app(AssistantEngine::class)->respond([AssistantTurn::guest('Je réserve, code WELCOME10')], $this->conversation->id));

    expect(($this->in)(fn () => BookingRequest::query()->sole()->coupon_code))->toBe('WELCOME10');
});

it('lets those who set prices manage codes, shows them to reception, and keeps hotels apart', function (): void {
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.coupons.store'), [
        'code' => 'orange-25', 'kind' => 'percent', 'value' => 25, 'active' => true, 'min_nights' => 2,
    ])->assertSessionHasNoErrors();
    $coupon = ($this->in)(fn () => Coupon::query()->sole());
    expect($coupon->code)->toBe('ORANGE-25');

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.coupons.store'), ['code' => 'ORANGE-25', 'kind' => 'percent', 'value' => 5, 'active' => true])->assertSessionHasErrors('code');
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.coupons.store'), ['code' => 'TOO-MUCH', 'kind' => 'percent', 'value' => 95, 'active' => true])->assertSessionHasErrors('value');
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.coupons.store'), ['code' => 'MINE', 'kind' => 'percent', 'value' => 5, 'active' => true])->assertForbidden();

    $other = Hotel::factory()->create();
    $otherOwner = staffMember($other, SystemRole::Owner);
    asStaffOf($other, $otherOwner)->put(route('hotel.coupons.update', $coupon), ['code' => 'X', 'kind' => 'percent', 'value' => 5, 'active' => true])->assertNotFound();
});
