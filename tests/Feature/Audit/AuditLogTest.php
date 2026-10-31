<?php

declare(strict_types=1);

use App\Domains\Audit\Audit;
use App\Domains\Audit\Exceptions\ImmutableAuditLog;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

it('records successful sign-ins and updates last_login_at', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(AuditLog::query()->where('event', 'auth.login')->where('causer_id', $user->id)->exists())->toBeTrue()
        ->and($user->refresh()->last_login_at)->not->toBeNull();
});

it('records failed sign-ins and lockouts', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 6) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    expect(AuditLog::query()->where('event', 'auth.failed')->count())->toBe(5)
        ->and(AuditLog::query()->where('event', 'auth.lockout')->exists())->toBeTrue();
});

it('records hotel creation and changes', function (): void {
    $hotel = Hotel::factory()->create(['name' => 'Old Name']);
    $hotel->update(['name' => 'New Name']);

    $updated = AuditLog::query()->where('event', 'hotel.updated')->sole();

    expect(AuditLog::query()->where('event', 'hotel.created')->exists())->toBeTrue()
        ->and($updated->properties->get('old'))->toBe(['name' => 'Old Name'])
        ->and($updated->properties->get('new'))->toBe(['name' => 'New Name']);
});

it('stamps entries with the current hotel and request context', function (): void {
    $hotel = Hotel::factory()->create();

    $entry = app(TenantContext::class)->run($hotel, fn () => Audit::record('test.event', 'Test'));

    expect($entry->hotel_id)->toBe($hotel->id)
        ->and($entry->ip)->not->toBeNull();
});

it('cannot be updated', function (): void {
    $entry = Audit::record('test.event', 'Test');

    $entry->update(['description' => 'Tampered']);
})->throws(ImmutableAuditLog::class);

it('cannot be deleted', function (): void {
    Audit::record('test.event', 'Test')->delete();
})->throws(ImmutableAuditLog::class);

it('adds a request ID to every response', function (): void {
    $this->get('/')->assertHeader('X-Request-Id');

    $this->withHeader('X-Request-Id', 'trace-12345678')
        ->get('/')
        ->assertHeader('X-Request-Id', 'trace-12345678');
});
