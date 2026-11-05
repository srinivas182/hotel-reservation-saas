<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Translation\TranslationAgent;
use App\Domains\Rooms\Models\Room;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\StayMessages\StayMessage;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\StarterTemplates;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class]);
    $this->travelTo('2026-11-10 07:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(function (): void {
        WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']);
        HotelAssistantSettings::current()->update([
            'stay_messages' => ['reminder' => ['enabled' => true, 'time' => '10:00'], 'checkout' => ['enabled' => true, 'time' => '08:00'], 'thank_you' => ['enabled' => true, 'time' => '18:00']],
            'review_url' => 'https://g.page/r/first-class-lodge/review',
        ]);
        foreach (['stay_reminder', 'checkout_reminder', 'stay_thank_you', 'staff_alert', 'owner_daily_summary'] as $name) {
            $starter = StarterTemplates::all()[$name];
            MessageTemplate::query()->create(['starter_key' => $name, 'name' => $name, 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]);
        }
    });
    $this->sent = [];
    Http::fake(['graph.facebook.com/v23.0/5550001/messages' => function ($request) {
        $this->sent[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.S'.count($this->sent)]]]);
    }]);
});

function consentingBooking(object $test, string $in, string $out, string $phone = '+2250700000001'): Booking
{
    $booking = book($test->hotel, $test->exe, $in, $out, phone: $phone);
    ($test->in)(fn () => $booking->guest->forceFill(['whatsapp_opt_in' => true, 'preferred_locale' => 'fr'])->save());

    return $booking;
}

function sentTemplates(object $test): array
{
    return array_map(fn (array $m): string => $m['template']['name'] ?? 'text', $test->sent);
}

it('sends the arrival reminder the day before, at the chosen time, once', function (): void {
    $booking = consentingBooking($this, '2026-11-11', '2026-11-13');

    $this->travelTo('2026-11-10 09:55:00');
    $this->artisan('stay-messages:send');
    expect($this->sent)->toBe([]);

    $this->travelTo('2026-11-10 10:05:00');
    $this->artisan('stay-messages:send');
    $this->artisan('stay-messages:send');

    expect(sentTemplates($this))->toBe(['stay_reminder'])
        ->and($this->sent[0]['to'])->toBe('2250700000001')
        ->and(array_column($this->sent[0]['template']['components'][0]['parameters'], 'text'))->toBe(['Awa', 'First Class Lodge', 'mercredi 11 novembre', '14:00', '-'])
        ->and(($this->in)(fn () => StayMessage::query()->where('booking_id', $booking->id)->sole()->how))->toBe('template');
});

it('uses free text when the guest wrote in the last 24 hours', function (): void {
    webhookPost($this, [inbound('wamid.G1', from: '2250700000001', name: 'Awa')]);
    consentingBooking($this, '2026-11-11', '2026-11-13');

    $this->travelTo('2026-11-10 10:05:00');
    $this->artisan('stay-messages:send');

    expect($this->sent[0]['type'])->toBe('text')
        ->and($this->sent[0]['text']['body'])->toContain('demain');
});

it('sends the check-out reminder and the thank-you on the departure day', function (): void {
    $this->travelTo('2026-11-09 15:00:00');
    $booking = consentingBooking($this, '2026-11-09', '2026-11-10');
    ($this->in)(fn () => app(BookingLifecycle::class)->checkIn($booking, [1 => Room::query()->where('room_category_id', $this->exe->id)->orderBy('number')->value('id')]));

    $this->travelTo('2026-11-10 08:10:00');
    $this->artisan('stay-messages:send');
    ($this->in)(fn () => app(BookingLifecycle::class)->checkOut($booking));

    $this->travelTo('2026-11-10 18:10:00');
    $this->artisan('stay-messages:send');

    expect(sentTemplates($this))->toBe(['checkout_reminder', 'stay_thank_you'])
        ->and($this->sent[1]['template']['components'][0]['parameters'][2]['text'])->toBe('https://g.page/r/first-class-lodge/review');
});

it('skips guests without consent, cancelled bookings and switched-off messages, and records why', function (): void {
    $noConsent = book($this->hotel, $this->exe, '2026-11-11', '2026-11-12', phone: '+2250700000002');
    $cancelled = consentingBooking($this, '2026-11-11', '2026-11-12', '+2250700000003');
    ($this->in)(fn () => app(BookingLifecycle::class)->cancel($cancelled, 'Changed plans'));

    $this->travelTo('2026-11-10 10:05:00');
    $this->artisan('stay-messages:send');

    expect($this->sent)->toBe([])
        ->and(($this->in)(fn () => StayMessage::query()->where('booking_id', $noConsent->id)->sole()->reason))->toBe('no_consent')
        ->and(($this->in)(fn () => StayMessage::query()->where('booking_id', $cancelled->id)->exists()))->toBeFalse();

    ($this->in)(fn () => HotelAssistantSettings::current()->update(['stay_messages' => ['reminder' => ['enabled' => false, 'time' => '10:00']]]));
    consentingBooking($this, '2026-11-11', '2026-11-12', '+2250700000004');
    $this->artisan('stay-messages:send');
    expect($this->sent)->toBe([]);
});

it('records consent ticked by reception and then reaches the guest', function (): void {
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->post(route('hotel.bookings.store'), bookingPayload($this->exe, [
        'check_in' => '2026-11-11', 'check_out' => '2026-11-12', 'phone' => '+2250700000005', 'whatsapp_consent' => true,
    ]))->assertSessionHasNoErrors();

    $booking = ($this->in)(fn () => Booking::query()->with('guest')->sole());
    expect($booking->whatsapp_consent_at)->not->toBeNull()->and($booking->guest->whatsapp_opt_in)->toBeTrue();

    $this->travelTo('2026-11-10 10:05:00');
    $this->artisan('stay-messages:send');

    expect(sentTemplates($this))->toBe(['stay_reminder'])
        ->and(($this->in)(fn () => Conversation::query()->count()))->toBe(1);
});

it('saves the stay message settings', function (): void {
    $manager = staffMember($this->hotel, SystemRole::Manager);

    asStaffOf($this->hotel, $manager)->put(route('hotel.assistant.update'), [
        'mode' => 'off', 'tone' => 'formal', 'languages' => ['fr'], 'daily_budget' => 5, 'kill_switch' => false,
        'request_hold_minutes' => 120, 'deposit_required' => false, 'deposit_percent' => 30, 'deposit_hours' => 24,
        'silence_minutes' => 20, 'review_url' => 'http://not-secure.example',
        'stay_messages' => ['reminder' => ['enabled' => true, 'time' => '09:30']],
    ])->assertSessionHasErrors('review_url');

    asStaffOf($this->hotel, $manager)->put(route('hotel.assistant.update'), [
        'mode' => 'off', 'tone' => 'formal', 'languages' => ['fr'], 'daily_budget' => 5, 'kill_switch' => false,
        'request_hold_minutes' => 120, 'deposit_required' => false, 'deposit_percent' => 30, 'deposit_hours' => 24,
        'silence_minutes' => 20, 'review_url' => 'https://g.page/r/lodge/review',
        'stay_messages' => ['reminder' => ['enabled' => true, 'time' => '09:30']],
    ])->assertSessionHasNoErrors();

    $settings = ($this->in)(fn () => HotelAssistantSettings::current());
    expect($settings->silence_minutes)->toBe(20)
        ->and($settings->stay_messages['reminder'])->toEqual(['enabled' => true, 'time' => '09:30']) // MySQL reorders JSON keys.
        ->and($settings->stay_messages['checkout'])->toEqual(['enabled' => false, 'time' => '08:00']);
});

it('runs a reception day: alert, take over, note, translation, stay messages and the owner summary', function (): void {
    $amara = staffMember($this->hotel, SystemRole::Reception);
    $owner = staffMember($this->hotel, SystemRole::Owner);
    ($this->in)(function () use ($amara, $owner): void {
        StaffAlertSettings::query()->create(['user_id' => $amara->id, 'alert_phone' => '+2250700000010', 'alerts' => ['handover']]);
        StaffAlertSettings::query()->create(['user_id' => $owner->id, 'alert_phone' => '+2250700000020', 'summary_time' => '21:00', 'summary_channels' => ['whatsapp']]);
    });
    TranslationAgent::fake(['We are sorry. A technician is on the way.']);

    // A guest complains; the conversation is passed to staff and Amara is alerted.
    webhookPost($this, [inbound('wamid.C1', from: '2250700000009', message: ['text' => ['body' => 'The air conditioning is broken!']], name: 'John Smith')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    ($this->in)(fn () => app(App\Domains\Conversations\ConversationHandover::class)->toStaff($conversation, 'complaint'));
    expect($this->sent[0]['to'])->toBe('2250700000010');

    // Amara takes over, notes, translates and replies.
    asStaffOf($this->hotel, $amara)->put(route('hotel.inbox.mode', $conversation), ['mode' => 'staff']);
    asStaffOf($this->hotel, $amara)->post(route('hotel.inbox.notes', $conversation), ['body' => 'Technician called, room 104']);
    asStaffOf($this->hotel, $amara)->post(route('hotel.inbox.translate', $conversation), ['text' => 'Nous sommes désolés. Un technicien arrive.', 'language' => 'en'])
        ->assertSessionHas('inertia.flash_data', fn (array $f): bool => $f['translation'] === 'We are sorry. A technician is on the way.');
    asStaffOf($this->hotel, $amara)->post(route('hotel.inbox.reply', $conversation), ['body' => 'We are sorry. A technician is on the way.']);
    expect(($this->in)(fn () => $conversation->fresh()->assigned_to))->toBe($amara->id);

    // Tomorrow's guest gets a reminder; the owner gets the evening summary.
    consentingBooking($this, '2026-11-11', '2026-11-12');
    $this->travelTo('2026-11-10 10:05:00');
    $this->artisan('stay-messages:send');
    $this->travelTo('2026-11-10 21:05:00');
    $this->artisan('owners:daily-summary');

    expect(sentTemplates($this))->toBe(['staff_alert', 'text', 'stay_reminder', 'owner_daily_summary']);
});
