<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Data for the browser tests (tests/e2e): the simulated hotel, plus a
 * platform admin with a known two-step secret and a go-live request.
 * Refuses to run in production.
 */
#[Signature('e2e:prepare')]
#[Description('Prepare a simulated staging for the browser tests (never in production)')]
final class PrepareEndToEnd extends Command
{
    public const ADMIN_EMAIL = 'admin.e2e@example.com';

    /** Base32 secret; the browser tests compute the codes from it. */
    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    /** The pilot hotel's owner (two-step login with its own secret: a code is accepted once). */
    public const OWNER_EMAIL = 'owner.lodge@example.com';

    public const OWNER_TOTP_SECRET = 'KRSXG5CTMVRXEZLU'; // gitleaks:allow test-only secret, simulated staging

    public function handle(TenantContext $context): int
    {
        if (app()->isProduction()) {
            $this->components->error('Not in production.');

            return self::FAILURE;
        }

        if ($this->call('simulation:prepare') !== self::SUCCESS) {
            return self::FAILURE;
        }

        User::query()->updateOrCreate(['email' => self::ADMIN_EMAIL], [
            'first_name' => 'Admin', 'last_name' => 'E2E', 'password' => Hash::make('password'),
        ])->forceFill([
            'platform_role' => PlatformRole::SuperAdmin, 'email_verified_at' => now(),
            'two_factor_secret' => encrypt(self::TOTP_SECRET), 'two_factor_recovery_codes' => encrypt((string) json_encode(['recovery-e2e'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        User::query()->where('email', self::OWNER_EMAIL)->firstOrFail()->forceFill([
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'two_factor_secret' => encrypt(self::OWNER_TOTP_SECRET), 'two_factor_recovery_codes' => encrypt((string) json_encode(['recovery-owner-e2e'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $hotel = Hotel::query()->where('slug', 'first-class-lodge')->firstOrFail();
        $context->run($hotel, fn () => OnboardingProgress::current()->forceFill(['golive_requested_at' => now(), 'golive_decision' => null, 'golive_decided_at' => null])->save());

        $this->components->info('Ready for browser tests: '.self::ADMIN_EMAIL.' / password (two-step secret '.self::TOTP_SECRET.'), '.self::OWNER_EMAIL.' / password (secret '.self::OWNER_TOTP_SECRET.').');

        return self::SUCCESS;
    }
}
