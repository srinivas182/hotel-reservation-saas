<?php

declare(strict_types=1);

use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;

it('sends platform staff without two-factor to the security page', function (): void {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

it('sends hotel owners without two-factor to the security page', function (): void {
    $owner = User::factory()->create();
    Hotel::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

it('lets owners and platform staff in once two-factor is confirmed', function (): void {
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
});

it('does not require two-factor for regular staff', function (): void {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
});

it('keeps the security page reachable while setup is pending', function (): void {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($admin)
        ->get(route('password.confirm'))
        ->assertOk();
});

it('signs in with a recovery code', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});
