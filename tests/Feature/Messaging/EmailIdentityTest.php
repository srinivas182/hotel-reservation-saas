<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Messaging\Email\HotelSender;
use App\Domains\Messaging\Email\Mail\ReplyToVerificationMail;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    $this->hotel = Hotel::factory()->create(['name' => 'First Class Lodge']);
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
});

function replyToToken(): string
{
    return Str::afterLast(Mail::sent(ReplyToVerificationMail::class)->last()->url, '/');
}

it('uses the hotel name as sender name by default and the chosen name once set', function (): void {
    $sender = app(HotelSender::class);
    expect($sender->from($this->hotel)->name)->toBe('First Class Lodge');

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.email.update'), ['email_sender_name' => 'First Class Lodge Korhogo', 'email_reply_to' => null]);

    expect($sender->from($this->hotel)->name)->toBe('First Class Lodge Korhogo')
        ->and($sender->from($this->hotel)->address)->toBe(config('mail.from.address'));
});

it('never uses an unconfirmed reply-to address', function (): void {
    app(TenantContext::class)->run($this->hotel, fn () => HotelSettings::current()->update(['email' => 'contact@lodge.test']));

    asStaffOf($this->hotel, $this->owner)
        ->put(route('hotel.email.update'), ['email_sender_name' => '', 'email_reply_to' => 'Reservations@Lodge.test'])
        ->assertSessionHasNoErrors();

    Mail::assertSent(ReplyToVerificationMail::class, fn ($mail) => $mail->hasTo('reservations@lodge.test'));
    expect(app(HotelSender::class)->replyTo($this->hotel)?->address)->toBe('contact@lodge.test');

    test()->get(route('email.reply-to.verify', replyToToken()))
        ->assertInertia(fn ($page) => $page->component('auth/reply-to-confirmed')->where('confirmed', true));

    expect(app(HotelSender::class)->replyTo($this->hotel)?->address)->toBe('reservations@lodge.test');
});

it('refuses expired and unknown confirmation links', function (): void {
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.email.update'), ['email_reply_to' => 'late@lodge.test']);
    $token = replyToToken();

    $this->travel(49)->hours();

    test()->get(route('email.reply-to.verify', $token))->assertInertia(fn ($page) => $page->where('confirmed', false));
    test()->get(route('email.reply-to.verify', 'nope'))->assertInertia(fn ($page) => $page->where('confirmed', false));
    expect(app(HotelSender::class)->replyTo($this->hotel))->toBeNull();
});

it('needs a new confirmation when the reply-to address changes', function (): void {
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.email.update'), ['email_reply_to' => 'one@lodge.test']);
    test()->get(route('email.reply-to.verify', replyToToken()));

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.email.update'), ['email_reply_to' => 'two@lodge.test']);

    expect(app(HotelSender::class)->replyTo($this->hotel))->toBeNull();
});

it('rejects sender names that could forge an address', function (): void {
    asStaffOf($this->hotel, $this->owner)
        ->put(route('hotel.email.update'), ['email_sender_name' => 'Bank <security@bank.test>'])
        ->assertSessionHasErrors('email_sender_name');
});

it('only lets settings managers change email settings', function (): void {
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::ReadOnly))
        ->put(route('hotel.email.update'), ['email_sender_name' => 'X'])
        ->assertForbidden();
});
