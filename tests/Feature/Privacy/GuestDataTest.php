<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\Guest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Conversations\Models\WhatsAppContact;
use App\Domains\Orders\Models\StayPhone;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $category = roomCategory($this->hotel, 'STD', 3);
    rateFor($this->hotel, $category);
    $this->booking = book($this->hotel, $category, '2026-11-10', '2026-11-12', phone: '+2250700000009');
    $this->guest = ($this->in)(fn () => Guest::query()->findOrFail($this->booking->guest_id));
    ($this->in)(function (): void {
        $contact = WhatsAppContact::query()->create(['wa_id' => '2250700000009', 'profile_name' => 'Awa K', 'guest_id' => $this->guest->id]);
        $conversation = Conversation::query()->create(['whatsapp_contact_id' => $contact->id, 'status' => 'open', 'last_message_at' => now()]);
        Message::query()->create(['conversation_id' => $conversation->id, 'direction' => 'in', 'type' => 'text', 'body' => 'Je suis allergique aux arachides', 'status' => 'received', 'source' => 'guest', 'sent_at' => now()]);
        StayPhone::query()->create(['booking_id' => $this->booking->id, 'phone' => '+2250700000010']);
    });
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
});

it('finds a guest and exports their data', function (): void {
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.privacy', ['q' => '0700000009']))->assertInertia(fn ($page) => $page->has('guests', 1));

    $json = asStaffOf($this->hotel, $this->owner)->get(route('hotel.privacy.export', $this->guest))->assertOk()->json();
    expect($json['guest']['first_name'])->toBe('Awa')->and($json['bookings'])->toHaveCount(1)->and($json['messages'][0]['text'])->toContain('arachides');
    expect(AuditLog::query()->where('event', 'privacy.guest_exported')->exists())->toBeTrue();
});

it('anonymises a guest but keeps bookings and amounts', function (): void {
    $total = $this->booking->total_minor;
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.privacy.anonymise', $this->guest), ['confirm' => 'oui'])->assertSessionHasErrors('confirm');
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.privacy.anonymise', $this->guest), ['confirm' => 'ANONYMISE'])->assertSessionHasNoErrors();

    ($this->in)(function () use ($total): void {
        $guest = $this->guest->fresh();
        expect($guest->last_name)->toBeNull()->and($guest->phone_e164)->toBeNull()->and($guest->first_name)->not->toBe('Awa')
            ->and(Message::query()->sole()->body)->toBe('[removed]')
            ->and(WhatsAppContact::query()->sole()->wa_id)->toStartWith('removed-')
            ->and(StayPhone::query()->count())->toBe(0)
            ->and(Booking::query()->sole()->total_minor)->toBe($total);
    });
});

it('is for owners and managers only', function (): void {
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->get(route('hotel.privacy'))->assertForbidden();
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))->get(route('hotel.privacy'))->assertOk();
});
