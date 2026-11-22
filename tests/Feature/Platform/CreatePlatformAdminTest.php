<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\PlatformRole;
use App\Models\User;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Support\Facades\Hash;

it('creates the first super admin with a one-time password and two-step login to set up', function (): void {
    $this->artisan('platform:create-admin', ['email' => 'Ops@CNH.ci', '--first-name' => 'Awa', '--last-name' => 'Traoré'])
        ->expectsOutputToContain('Platform user created: ops@cnh.ci (super_admin).')
        ->expectsOutputToContain('Two-step login is required')
        ->assertSuccessful();

    $user = User::query()->where('email', 'ops@cnh.ci')->sole();
    expect($user->platform_role)->toBe(PlatformRole::SuperAdmin)
        ->and($user->first_name)->toBe('Awa')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasConfirmedTwoFactor())->toBeFalse()
        ->and(Hash::check('password', $user->password))->toBeFalse();

    // Signing in leads straight to the two-step login set-up.
    $this->actingAs($user)->get('/platform')->assertRedirect(route('security.edit'));
});

it('refuses a second super admin unless forced, and gives other roles to existing accounts', function (): void {
    $this->artisan('platform:create-admin', ['email' => 'first@example.com'])->assertSuccessful();
    $this->artisan('platform:create-admin', ['email' => 'second@example.com'])->assertFailed();
    $this->artisan('platform:create-admin', ['email' => 'second@example.com', '--force' => true])->assertSuccessful();

    $existing = User::query()->create(['first_name' => 'Koffi', 'last_name' => 'Sales', 'email' => 'koffi@example.com', 'password' => Hash::make('keep-this-one')]);
    $this->artisan('platform:create-admin', ['email' => 'koffi@example.com', '--role' => 'sales'])->assertSuccessful();

    expect($existing->refresh()->platform_role)->toBe(PlatformRole::Sales)
        ->and(Hash::check('keep-this-one', $existing->password))->toBeTrue();

    $this->artisan('platform:create-admin', ['email' => 'koffi@example.com', '--role' => 'sales', '--reset-password' => true])
        ->expectsOutputToContain('Password (shown once)')->assertSuccessful();
    expect(Hash::check('keep-this-one', $existing->refresh()->password))->toBeFalse();

    $this->artisan('platform:create-admin', ['email' => 'not-an-email'])->assertExitCode(2);
    $this->artisan('platform:create-admin', ['email' => 'x@example.com', '--role' => 'king'])->assertExitCode(2);
});

it('seeds the demo hotels without the test data generator, but no known super admin on a server', function (): void {
    // Staging (simulation:prepare): hotels and their staff, but no known super admin.
    $this->seed(LocalDemoSeeder::class);
    expect(User::query()->where('email', 'admin@example.com')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'owner.lodge@example.com')->exists())->toBeTrue();
});

it('seeds the demo super admin on a developer machine', function (): void {
    $this->app['env'] = 'local';
    $this->seed(LocalDemoSeeder::class);

    expect(User::query()->where('email', 'admin@example.com')->sole()->platform_role)->toBe(PlatformRole::SuperAdmin)
        ->and(User::query()->whereIn('email', ['owner.lodge@example.com', 'reception.lodge@example.com', 'owner.city@example.com', 'reception.city@example.com'])->count())->toBe(4)
        ->and(Hash::check('password', User::query()->where('email', 'reception.lodge@example.com')->sole()->password))->toBeTrue();

    // No factory or fake() left in the seeder (staging images have no Faker).
    expect(file_get_contents(database_path('seeders/LocalDemoSeeder.php')))->not->toContain('factory(')->not->toContain('fake(');
});

it('skips two-step login on a testing server when switched off, never in production', function (): void {
    $this->artisan('platform:create-admin', ['email' => 'tester@example.com'])->assertSuccessful();
    $admin = User::query()->where('email', 'tester@example.com')->sole();

    config(['auth.two_factor_required' => false]);
    $this->actingAs($admin)->get('/platform')->assertOk();

    $this->app['env'] = 'production';
    $this->actingAs($admin)->get('/platform')->assertRedirect(route('security.edit'));
});
