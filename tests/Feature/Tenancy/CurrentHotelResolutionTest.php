<?php

declare(strict_types=1);

use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\Http\Middleware\ResolveCurrentHotel;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->memberships = app(HotelMembershipService::class);
    $this->user = User::factory()->create();
    $this->hotelA = Hotel::factory()->create(['name' => 'Alpha Lodge']);
    $this->hotelB = Hotel::factory()->create(['name' => 'Beta Inn']);
});

it('selects the first accessible hotel when none is stored', function (): void {
    $this->memberships->add($this->hotelB, $this->user);
    $this->memberships->add($this->hotelA, $this->user);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSessionHas(ResolveCurrentHotel::SESSION_KEY, $this->hotelA->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenancy.current.id', $this->hotelA->id)
            ->has('tenancy.hotels', 2));
});

it('refuses access to a hotel the user is not a member of', function (): void {
    $this->memberships->add($this->hotelA, $this->user);

    $this->actingAs($this->user)
        ->withSession([ResolveCurrentHotel::SESSION_KEY => $this->hotelB->id])
        ->get(route('dashboard'))
        ->assertForbidden()
        ->assertSessionMissing(ResolveCurrentHotel::SESSION_KEY);
});

it('refuses access once a membership is suspended', function (): void {
    $this->memberships->add($this->hotelA, $this->user);
    $this->memberships->suspend($this->hotelA, $this->user);

    $this->actingAs($this->user)
        ->withSession([ResolveCurrentHotel::SESSION_KEY => $this->hotelA->id])
        ->get(route('dashboard'))
        ->assertForbidden();
});

it('refuses access to a suspended hotel', function (): void {
    $this->memberships->add($this->hotelA, $this->user);
    $this->hotelA->update(['status' => HotelStatus::Suspended]);

    $this->actingAs($this->user)
        ->withSession([ResolveCurrentHotel::SESSION_KEY => $this->hotelA->id])
        ->get(route('dashboard'))
        ->assertForbidden();
});

it('lets a member switch hotels', function (): void {
    $this->memberships->add($this->hotelA, $this->user);
    $this->memberships->add($this->hotelB, $this->user);

    $this->actingAs($this->user)
        ->post(route('hotels.switch', $this->hotelB))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(ResolveCurrentHotel::SESSION_KEY, $this->hotelB->id);
});

it('refuses switching to a hotel the user does not belong to', function (): void {
    $this->memberships->add($this->hotelA, $this->user);

    $this->actingAs($this->user)
        ->post(route('hotels.switch', $this->hotelB))
        ->assertForbidden();
});

it('lets users without hotels in, but not into hotel-only routes', function (): void {
    Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'hotel'])
        ->get('/_fixtures/hotel-only', fn (): string => 'ok');

    $this->actingAs($this->user)->get(route('dashboard'))->assertOk();
    $this->actingAs($this->user)->get('/_fixtures/hotel-only')->assertForbidden();
});
