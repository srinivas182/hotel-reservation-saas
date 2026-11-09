<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Platform\Support\Mail\SupportSessionMail;
use App\Domains\Platform\Support\SupportAccess;
use App\Domains\Platform\Support\SupportSession;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Rooms/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    $this->owner = User::factory()->withTwoFactor()->create(['email' => 'owner@lodge.example']);
    $this->hotel = Hotel::factory()->create(['owner_user_id' => $this->owner->id]);
    staffMember($this->hotel, SystemRole::Owner, ['email' => 'staff-owner@lodge.example']);
    $this->ops = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create();
    // New hotels ask first since S17.3d; these tests cover "allowed with notification" unless they say otherwise.
    app(TenantContext::class)->run($this->hotel, fn () => HotelSettings::current()->update(['support_access' => 'notify']));
});

it('opens a read-only session the owner is told about, where nothing can be changed', function (): void {
    $this->actingAs($this->ops)->post(route('platform.hotels.support.start', $this->hotel), ['reason' => 'Owner called: rates missing', 'mode' => 'read'])
        ->assertRedirect(route('hotel.today'));
    Mail::assertQueued(SupportSessionMail::class, fn (SupportSessionMail $m): bool => $m->hasTo('owner@lodge.example') && $m->kind === 'started');

    $this->actingAs($this->ops)->get(route('hotel.today'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('supportSession.mode', 'read')->where('auth.permissions', fn ($p) => collect($p)->every(fn ($x) => str_ends_with($x, '.view'))));
    $this->actingAs($this->ops)->get(route('hotel.rooms'))->assertOk();
    $this->actingAs($this->ops)->post(route('hotel.room-categories.store'), categoryPayload())->assertForbidden();
    $this->actingAs($this->ops)->get(route('hotel.whatsapp'))->assertForbidden();

    expect(app(TenantContext::class)->run($this->hotel, fn () => AuditLog::query()->where('event', 'support.session_started')->where('hotel_id', $this->hotel->id)->count()))->toBe(1);
});

it('records changes made in a session with changes, and never reaches roles, WhatsApp, staff or billing', function (): void {
    $this->actingAs($this->ops)->post(route('platform.hotels.support.start', $this->hotel), ['reason' => 'Set up the rooms with the owner', 'mode' => 'write']);

    $this->actingAs($this->ops)->post(route('hotel.room-categories.store'), categoryPayload())->assertSessionHasNoErrors();
    expect(app(TenantContext::class)->run($this->hotel, fn () => RoomCategory::query()->count()))->toBe(1);

    $entry = AuditLog::query()->where('event', 'rooms.category_created')->sole();
    expect($entry->hotel_id)->toBe($this->hotel->id)
        ->and($entry->properties['support_session'])->toBe(SupportSession::query()->sole()->id);

    $this->actingAs($this->ops)->get(route('hotel.whatsapp'))->assertForbidden();
    expect(app(SupportAccess::class)->allows('roles.manage'))->toBeFalse();
});

it('ends after 30 minutes, can be extended once, and can be ended', function (): void {
    $this->actingAs($this->ops)->post(route('platform.hotels.support.start', $this->hotel), ['reason' => 'Check the inbox', 'mode' => 'read']);

    $this->travel(25)->minutes();
    $this->actingAs($this->ops)->post(route('support.extend'))->assertSessionHasNoErrors();
    $this->actingAs($this->ops)->post(route('support.extend'))->assertSessionHasErrors('support');

    $this->travel(34)->minutes(); // 59 minutes in: still open
    $this->actingAs($this->ops)->get(route('hotel.today'))->assertOk();
    $this->travel(2)->minutes();
    $this->actingAs($this->ops)->get(route('hotel.today'))->assertForbidden();

    $this->actingAs($this->ops)->post(route('platform.hotels.support.start', $this->hotel), ['reason' => 'Second look', 'mode' => 'read']);
    $this->actingAs($this->ops)->post(route('support.end'))->assertRedirect(route('platform.hotels.show', $this->hotel));
    $this->actingAs($this->ops)->get(route('hotel.today'))->assertForbidden();
    expect(SupportSession::query()->where('status', 'ended')->count())->toBe(1);
});

it('waits for the owner when the hotel asks to approve each session', function (): void {
    app(TenantContext::class)->run($this->hotel, fn () => HotelSettings::current()->update(['support_access' => 'ask_first']));

    $this->actingAs($this->ops)->post(route('platform.hotels.support.start', $this->hotel), ['reason' => 'Invoice question', 'mode' => 'read'])->assertRedirect();
    $session = SupportSession::query()->sole();
    expect($session->status)->toBe('pending');
    Mail::assertQueued(SupportSessionMail::class, fn (SupportSessionMail $m): bool => $m->kind === 'approval');
    $this->actingAs($this->ops)->post(route('platform.support.enter', $session))->assertForbidden();

    $owner = User::query()->where('email', 'staff-owner@lodge.example')->sole();
    asStaffOf($this->hotel, $owner)->get(route('hotel.support.index'))->assertInertia(fn ($page) => $page->where('sessions.0.status', 'pending'));
    asStaffOf($this->hotel, $owner)->post(route('hotel.support.decide', $session), ['approve' => true])->assertSessionHasNoErrors();

    $this->flushSession();
    $this->actingAs($this->ops)->post(route('platform.support.enter', $session))->assertRedirect(route('hotel.today'));
    $this->actingAs($this->ops)->get(route('hotel.today'))->assertOk();
});

it('is only for operations and super admins, and only for one’s own session', function (): void {
    $this->actingAs(User::factory()->platformAdmin(PlatformRole::Finance)->withTwoFactor()->create())
        ->post(route('platform.hotels.support.start', $this->hotel), ['reason' => 'Look around', 'mode' => 'read'])->assertForbidden();

    $this->actingAs($this->ops)->post(route('platform.hotels.support.start', $this->hotel), ['reason' => 'Look around', 'mode' => 'read']);
    $other = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create();
    $this->flushSession();
    $this->actingAs($other)->post(route('platform.support.enter', SupportSession::query()->sole()))->assertForbidden();
});
