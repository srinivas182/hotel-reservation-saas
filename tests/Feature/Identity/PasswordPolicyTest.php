<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;

function updatePassword(User $user, string $password): Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => $password,
            'password_confirmation' => $password,
        ]);
}

it('rejects passwords shorter than 12 characters', function (): void {
    updatePassword(User::factory()->create(), 'Short-pass1')->assertSessionHasErrors('password');
});

it('rejects passwords without letters and numbers', function (): void {
    updatePassword(User::factory()->create(), 'only-letters-here')->assertSessionHasErrors('password');
});

it('accepts a strong password', function (): void {
    updatePassword(User::factory()->create(), 'Correct-Horse-Battery-9')->assertSessionHasNoErrors();
});

it('rejects passwords found in known data breaches', function (): void {
    config(['security.check_breached_passwords' => true]);

    $password = 'Password1234';
    $hash = strtoupper(sha1($password));

    Http::fake([
        'api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':42'),
    ]);

    updatePassword(User::factory()->create(), $password)->assertSessionHasErrors('password');
});
