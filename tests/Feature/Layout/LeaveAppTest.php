<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\PlatformRole;
use App\Models\User;

it('sends the app to a plain page with a full page load instead of a pop-up', function (): void {
    // An app (Inertia) request that ends on the website, as after logging out.
    $this->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'x'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url('/'));

    $this->get('/help', ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', url('/help'));

    // Ordinary visits and app screens are untouched.
    $this->get('/')->assertOk();
    $admin = User::factory()->platformAdmin(PlatformRole::SuperAdmin)->withTwoFactor()->create();
    $version = (string) app(App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $this->actingAs($admin)->get('/platform', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()->assertHeader('X-Inertia', 'true');
});

it('logs out to the website with a full page load', function (): void {
    $admin = User::factory()->platformAdmin(PlatformRole::SuperAdmin)->withTwoFactor()->create();
    $this->actingAs($admin)->post('/logout', [], ['X-Inertia' => 'true'])->assertRedirect();
    $this->get('/', ['X-Inertia' => 'true'])->assertStatus(409);
});
