<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Audit\Audit;
use App\Domains\Identity\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creates the first platform user on a new server (staging or production),
 * or gives a platform role to an existing account. No account exists by
 * default outside local development, so nobody can sign in with a known
 * password.
 *
 * A new account gets a random password, shown once. Two-step login is
 * required at the first sign-in (EnsureTwoFactorEnrolled).
 */
#[Signature('platform:create-admin
    {email : E-mail address of the person}
    {--first-name= : First name (new accounts)}
    {--last-name= : Last name (new accounts)}
    {--role=super_admin : super_admin, operations, finance or sales}
    {--force : Allow another super admin when one already exists}
    {--reset-password : For an existing account, set a new random password}')]
#[Description('Create the first platform super admin (or another platform user) and show a one-time password')]
final class CreatePlatformAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $role = PlatformRole::tryFrom((string) $this->option('role'));

        $validator = Validator::make(['email' => $email, 'role' => $this->option('role')], [
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::in(array_map(fn (PlatformRole $r): string => $r->value, PlatformRole::cases()))],
        ]);
        if ($validator->fails() || $role === null) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::INVALID;
        }

        if ($role === PlatformRole::SuperAdmin && ! $this->option('force')
            && User::query()->where('platform_role', PlatformRole::SuperAdmin->value)->where('email', '!=', $email)->exists()) {
            $this->components->error('A super admin already exists. Sign in as them and add people in Platform → Users, or use --force.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        $password = null;

        if ($user === null) {
            $password = Str::password(20, symbols: false);
            $user = new User;
            $user->forceFill([
                'first_name' => trim((string) ($this->option('first-name') ?: 'Platform')),
                'last_name' => trim((string) ($this->option('last-name') ?: 'Admin')),
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'platform_role' => $role,
            ])->save();
            $what = 'created';
        } else {
            if ($this->option('reset-password')) {
                $password = Str::password(20, symbols: false);
                // A new password also ends the two-step login, which is set up again.
                $user->forceFill(['password' => Hash::make($password), 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
            }
            $user->forceFill(['platform_role' => $role, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            $what = 'updated';
        }

        Audit::record('platform.admin_'.$what, 'Platform user '.$what.' from the command line', subject: $user, properties: [
            'role' => $role->value, 'password_set' => $password !== null,
        ]);

        $this->components->info("Platform user {$what}: {$email} ({$role->value}).");
        $rows = [['Sign in at', rtrim((string) config('app.url'), '/').'/login'], ['E-mail', $email]];
        if ($password !== null) {
            $rows[] = ['Password (shown once)', $password];
        }
        $this->table(['', ''], $rows);
        $this->components->warn('Two-step login is required at the first sign-in: have an authenticator app ready.');

        return self::SUCCESS;
    }
}
