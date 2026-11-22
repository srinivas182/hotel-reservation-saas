<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function validHotelPayload(array $overrides = []): array
{
    return [
        'name' => 'First Class Lodge',
        'slug' => '',
        'legal_name' => 'Colena Investment',
        'country_code' => 'ci',
        'timezone' => 'Africa/Abidjan',
        'currency' => 'xof',
        'default_locale' => 'fr',
        'owner_email' => '',
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
    $this->support = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create();
});

it('is closed to hotel staff', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('platform.hotels.index'))
        ->assertForbidden();
});

it('lists hotels for platform staff', function (): void {
    Hotel::factory()->count(3)->create();

    $this->actingAs($this->support)
        ->get(route('platform.hotels.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/hotels/index')
            ->has('hotels.data', 3)
            ->where('canCreate', false));
});

it('lets a super admin create a hotel with an owner', function (): void {
    $owner = User::factory()->create(['email' => 'owner@lodge.test']);

    $this->actingAs($this->admin)
        ->post(route('platform.hotels.store'), validHotelPayload(['owner_email' => 'Owner@Lodge.test']))
        ->assertSessionHasNoErrors();

    $hotel = Hotel::query()->sole();

    expect($hotel->slug)->toBe('first-class-lodge')
        ->and($hotel->country_code)->toBe('CI')
        ->and($hotel->currency)->toBe('XOF')
        ->and($hotel->status)->toBe(HotelStatus::Trial)
        ->and($hotel->owner_user_id)->toBe($owner->id)
        ->and(app(HotelMembershipService::class)->accessibleHotel($owner, $hotel->id))->not->toBeNull()
        ->and(app(App\Domains\Access\StaffService::class)->isOwner($hotel, $owner))->toBeTrue();

    $created = AuditLog::query()->where('event', 'hotel.created')->sole();
    expect($created->causer_id)->toBe($this->admin->id);
});

it('makes generated slugs unique', function (): void {
    Hotel::factory()->create(['slug' => 'first-class-lodge']);

    $this->actingAs($this->admin)->post(route('platform.hotels.store'), validHotelPayload());

    expect(Hotel::query()->where('slug', 'first-class-lodge-2')->exists())->toBeTrue();
});

it('validates hotel details', function (): void {
    $this->actingAs($this->admin)
        ->post(route('platform.hotels.store'), validHotelPayload([
            'timezone' => 'Mars/Olympus',
            'country_code' => 'CIV',
            'owner_email' => 'not-an-email',
        ]))
        ->assertSessionHasErrors(['timezone', 'country_code', 'owner_email']);
});

it('creates the owner\'s account with the password the admin gives', function (): void {
    $this->actingAs($this->admin)
        ->post(route('platform.hotels.store'), validHotelPayload([
            'owner_email' => 'New.Owner@Lodge.test',
            'owner_first_name' => 'Awa',
            'owner_last_name' => 'Koné',
            'owner_password' => 'Lodge-Korhogo-2026!',
        ]))
        ->assertSessionHasNoErrors();

    $owner = User::query()->where('email', 'new.owner@lodge.test')->sole();
    $hotel = Hotel::query()->sole();

    expect($hotel->owner_user_id)->toBe($owner->id)
        ->and(Illuminate\Support\Facades\Hash::check('Lodge-Korhogo-2026!', $owner->password))->toBeTrue()
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and(app(App\Domains\Access\StaffService::class)->roleOf($hotel, $owner)?->name)->toBe('owner')
        ->and(AuditLog::query()->where('event', 'platform.hotel_user_created')->sole()->properties)->not->toHaveKey('password');

    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => 'new.owner@lodge.test', 'password' => 'Lodge-Korhogo-2026!']);
    $this->assertAuthenticatedAs($owner);
});

it('asks for a name and password before creating an unknown owner, and creates nothing', function (): void {
    $this->actingAs($this->admin)
        ->post(route('platform.hotels.store'), validHotelPayload(['owner_email' => 'nobody@example.com']))
        ->assertSessionHasErrors(['owner_first_name', 'owner_last_name', 'owner_password']);

    $this->actingAs($this->admin)
        ->post(route('platform.hotels.store'), validHotelPayload(['owner_email' => 'nobody@example.com', 'owner_first_name' => 'A', 'owner_last_name' => 'B', 'owner_password' => 'short']))
        ->assertSessionHasErrors(['owner_password']);

    expect(Hotel::query()->count())->toBe(0)->and(User::query()->where('email', 'nobody@example.com')->exists())->toBeFalse();
});

it('adds a new person to a hotel with a name and password', function (): void {
    $hotel = Hotel::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('platform.hotels.members.store', $hotel), ['email' => 'night@lodge.test', 'role' => 'reception'])
        ->assertSessionHasErrors(['first_name', 'password']);

    $this->actingAs($this->admin)
        ->post(route('platform.hotels.members.store', $hotel), ['email' => 'night@lodge.test', 'role' => 'reception', 'first_name' => 'Koffi', 'last_name' => 'Yao', 'password' => 'Night-Shift-2026!'])
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'night@lodge.test')->sole();
    expect(app(App\Domains\Access\StaffService::class)->roleOf($hotel, $user)?->name)->toBe('reception')
        ->and(Illuminate\Support\Facades\Hash::check('Night-Shift-2026!', $user->password))->toBeTrue();
});

it('does not let support staff create or change hotels', function (): void {
    $hotel = Hotel::factory()->create();

    $this->actingAs($this->support)->post(route('platform.hotels.store'), validHotelPayload())->assertForbidden();
    $this->actingAs($this->support)
        ->put(route('platform.hotels.update', $hotel), validHotelPayload(['status' => 'active']))
        ->assertForbidden();
});

it('lets a super admin update a hotel and audits the change', function (): void {
    $hotel = Hotel::factory()->create(['name' => 'Old Name', 'status' => HotelStatus::Trial]);

    $this->actingAs($this->admin)
        ->put(route('platform.hotels.update', $hotel), validHotelPayload(['name' => 'New Name', 'slug' => $hotel->slug, 'status' => 'active']))
        ->assertRedirect(route('platform.hotels.edit', $hotel));

    expect($hotel->refresh()->name)->toBe('New Name')
        ->and($hotel->status)->toBe(HotelStatus::Active)
        ->and(AuditLog::query()->where('event', 'hotel.updated')->exists())->toBeTrue();
});

it('adds staff with a chosen role', function (): void {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['email' => 'chef@lodge.test']);

    $this->actingAs($this->admin)
        ->post(route('platform.hotels.members.store', $hotel), ['email' => 'chef@lodge.test', 'role' => 'restaurant_bar'])
        ->assertSessionHasNoErrors();

    expect(app(App\Domains\Access\StaffService::class)->roleOf($hotel, $user)?->name)->toBe('restaurant_bar');
});

it('adds and removes hotel staff', function (): void {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['email' => 'reception@lodge.test']);

    $this->actingAs($this->admin)
        ->post(route('platform.hotels.members.store', $hotel), ['email' => 'reception@lodge.test'])
        ->assertSessionHasNoErrors();

    expect($hotel->activeMembers()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->delete(route('platform.hotels.members.destroy', [$hotel, $user]))
        ->assertRedirect(route('platform.hotels.edit', $hotel));

    expect($hotel->members()->count())->toBe(0);
});

it('does not remove the hotel owner', function (): void {
    $owner = User::factory()->create();
    $hotel = Hotel::factory()->ownedBy($owner)->create();
    app(HotelMembershipService::class)->add($hotel, $owner);

    $this->actingAs($this->admin)
        ->delete(route('platform.hotels.members.destroy', [$hotel, $owner]))
        ->assertStatus(422);
});
