<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Access\HotelRoles;
use App\Domains\Access\StaffService;
use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Bookings\BookingChannel;
use App\Domains\Bookings\BookingService;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\NewBooking;
use App\Domains\Deposits\Deposits;
use App\Domains\Deposits\Models\MerchantAccount;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Onboarding\Legal;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\Workspace\Models\StaffPreference;
use App\Domains\Workspace\Tours;
use App\Models\User;
use Carbon\CarbonImmutable;
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

    /** New receptionists, one per browser project, who have not seen the welcome tour yet. */
    public const NEWCOMER_EMAILS = ['new.phone.lodge@example.com', 'new.desktop.lodge@example.com'];

    public function handle(TenantContext $context, StaffService $staff, HotelRoles $roles, Legal $legal): int
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

        // Sprint E: the seeded staff have seen the tours, so they never cover
        // the screens under test; the newcomers see the welcome tour once.
        $context->run($hotel, function () use ($hotel, $staff, $roles, $legal): void {
            foreach (self::NEWCOMER_EMAILS as $email) {
                $newcomer = User::query()->updateOrCreate(['email' => $email], [
                    'first_name' => 'Nouvelle', 'last_name' => 'Recrue', 'password' => Hash::make('password'),
                ]);
                $newcomer->forceFill(['email_verified_at' => now()])->save();
                if (! $hotel->members()->whereKey($newcomer->getKey())->exists()) {
                    $staff->assign($hotel, $newcomer, $roles->system($hotel, SystemRole::Reception));
                }
                $legal->accept($newcomer, $legal->missing($newcomer), null, $hotel->id);
            }

            foreach ($hotel->members()->get() as $member) {
                StaffPreference::for($member)->forceFill([
                    'ui_mode' => null,
                    'tours_done' => in_array($member->email, self::NEWCOMER_EMAILS, true) ? [] : Tours::KEYS,
                ])->save();
            }
        });

        // Sprint F: a simulated mobile-money account and, per browser project,
        // a tentative booking whose deposit link the guest test pays.
        $links = $context->run($hotel, function (): array {
            MerchantAccount::query()->updateOrCreate([], ['driver' => 'simulation', 'mode' => 'test', 'credentials' => [], 'active' => true]);
            // Sprint H: the WhatsApp kit's links need the hotel's number.
            $place = \App\Domains\Hotel\Models\HotelSettings::current();
            if ($place->whatsapp_display_e164 === null) {
                $place->forceFill(['whatsapp_display_e164' => '+2250500000000'])->save();
            }
            HotelAssistantSettings::current()->update(['deposit_online' => true, 'deposit_allow_full' => true, 'deposit_refund_days' => 2]);
            $category = RoomCategory::query()->where('active', true)->orderBy('sort_order')->firstOrFail();
            $links = [];

            foreach (['phone' => 40, 'desktop' => 44] as $project => $offset) {
                $booking = app(BookingService::class)->create(new NewBooking(
                    stay: new StayRequest((string) $category->id, CarbonImmutable::today()->addDays($offset), CarbonImmutable::today()->addDays($offset + 2), adults: 2),
                    guest: ['first_name' => 'Mariam', 'last_name' => 'Acompte '.ucfirst($project), 'phone' => null, 'email' => null, 'preferred_locale' => 'fr'],
                    channel: BookingChannel::WhatsApp,
                    status: BookingStatus::Tentative,
                    tentativeHours: 24,
                ));
                $link = app(Deposits::class)->linkFor($booking);
                $links[$project] = ['token' => $link->token, 'booking' => $booking->id, 'reference' => $booking->reference];
            }

            return $links;
        });
        @mkdir(base_path('tests/e2e/.auth'), 0755, true);
        file_put_contents(base_path('tests/e2e/.auth/deposits.json'), json_encode($links, JSON_PRETTY_PRINT));

        $this->components->info('Ready for browser tests: '.self::ADMIN_EMAIL.' / password (two-step secret '.self::TOTP_SECRET.'), '.self::OWNER_EMAIL.' / password (secret '.self::OWNER_TOTP_SECRET.').');

        return self::SUCCESS;
    }
}
