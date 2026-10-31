<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
});

it('lists users for platform staff only', function (): void {
    User::factory()->count(3)->create();

    $this->actingAs($this->admin)->get(route('platform.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('platform/users/index')->where('users.total', 4));

    $this->actingAs(User::factory()->create())->get(route('platform.users.index'))->assertForbidden();
});

it('invites MCS staff with a password link', function (): void {
    Notification::fake();

    $this->actingAs($this->admin)->post(route('platform.users.store'), [
        'first_name' => 'Asha',
        'last_name' => 'Rao',
        'email' => 'Asha@MCS.test',
        'platform_role' => 'support',
    ])->assertSessionHasNoErrors();

    $staff = User::query()->where('email', 'asha@mcs.test')->sole();

    expect($staff->platform_role)->toBe(PlatformRole::Support)
        ->and($staff->requiresTwoFactor())->toBeTrue();

    Notification::assertSentTo($staff, ResetPassword::class);
});

it('deactivates a user: no new sign-ins and existing sessions end', function (): void {
    $user = User::factory()->create();

    $this->actingAs($this->admin)->post(route('platform.users.deactivate', $user))->assertRedirect();

    expect($user->refresh()->deactivated_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'platform.user_deactivated')->exists())->toBeTrue();

    // Existing session: the next request signs them out.
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    // New sign-in with the right password is refused.
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->actingAs($this->admin)->post(route('platform.users.reactivate', $user));
    expect($user->refresh()->deactivated_at)->toBeNull();
});

it('does not let admins deactivate themselves or support staff deactivate anyone', function (): void {
    $this->actingAs($this->admin)->post(route('platform.users.deactivate', $this->admin))->assertStatus(422);

    $support = User::factory()->platformAdmin(PlatformRole::Support)->withTwoFactor()->create();
    $this->actingAs($support)->post(route('platform.users.deactivate', User::factory()->create()))->assertForbidden();
});
