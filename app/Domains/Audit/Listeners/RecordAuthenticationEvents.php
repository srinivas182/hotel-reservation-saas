<?php

declare(strict_types=1);

namespace App\Domains\Audit\Listeners;

use App\Domains\Audit\Audit;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Writes authentication and two-factor events to the audit log, and keeps
 * users.last_login_at current.
 */
final class RecordAuthenticationEvents
{
    public function login(Login $event): void
    {
        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            Audit::record('auth.login', 'Signed in', subject: $event->user, causer: $event->user);
        }
    }

    public function failed(Failed $event): void
    {
        Audit::record('auth.failed', 'Failed sign-in attempt', properties: [
            'email' => $this->email($event->credentials),
        ]);
    }

    public function lockout(Lockout $event): void
    {
        Audit::record('auth.lockout', 'Sign-in temporarily locked after repeated failures', properties: [
            'email' => Str::lower((string) $event->request->input('email')),
        ]);
    }

    public function logout(Logout $event): void
    {
        if ($event->user instanceof User) {
            Audit::record('auth.logout', 'Signed out', subject: $event->user, causer: $event->user);
        }
    }

    public function passwordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            Audit::record('auth.password_reset', 'Password reset', subject: $event->user, causer: $event->user);
        }
    }

    public function twoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        Audit::record('auth.2fa.enabled', 'Two-factor authentication enabled', subject: $event->user, causer: $event->user);
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        Audit::record('auth.2fa.disabled', 'Two-factor authentication disabled', subject: $event->user, causer: $event->user);
    }

    public function recoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        Audit::record('auth.2fa.recovery_codes', 'Recovery codes regenerated', subject: $event->user, causer: $event->user);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            Logout::class => 'logout',
            PasswordReset::class => 'passwordReset',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'twoFactorDisabled',
            RecoveryCodesGenerated::class => 'recoveryCodesGenerated',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $credentials
     */
    private function email(array $credentials): ?string
    {
        $email = $credentials['email'] ?? null;

        return is_string($email) ? Str::lower($email) : null;
    }
}
