<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\HotelRoles;
use App\Domains\Access\StaffService;
use App\Domains\Access\SystemRole;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Rates\BreakfastMode;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Rooms\RoomStatus;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data: two hotels, each with an owner and a receptionist, and (on a
 * developer's machine only) a platform super admin. All demo accounts use
 * the password "password". Also used by `simulation:prepare` on staging.
 * Owners and the admin are asked to set up two-factor on first sign-in.
 */
class LocalDemoSeeder extends Seeder
{
    public function run(StaffService $staff, HotelRoles $roles): void
    {
        // Plain models, not factories: staging images are built without Faker.
        // The demo super admin only on a developer's machine: a public staging
        // server gets its first admin from `platform:create-admin` instead.
        if (app()->environment('local')) {
            $this->user('Platform', 'Admin', 'admin@example.com', PlatformRole::SuperAdmin);
        }

        $hotels = [
            ['First Class Lodge', 'first-class-lodge', 'CI', 'Africa/Abidjan', 'XOF', 'fr', 'lodge'],
            ['Demo City Hotel', 'demo-city-hotel', 'ZA', 'Africa/Johannesburg', 'ZAR', 'en', 'city'],
        ];

        foreach ($hotels as [$name, $slug, $country, $timezone, $currency, $locale, $key]) {
            $owner = $this->user('Owner', $name, "owner.{$key}@example.com");
            $receptionist = $this->user('Reception', $name, "reception.{$key}@example.com");

            $hotel = Hotel::query()->create([
                'name' => $name,
                'slug' => $slug,
                'country_code' => $country,
                'timezone' => $timezone,
                'currency' => $currency,
                'default_locale' => $locale,
                'status' => HotelStatus::Active,
                'owner_user_id' => $owner->getKey(),
            ]);

            $staff->assign($hotel, $owner, $roles->system($hotel, SystemRole::Owner));
            $staff->assign($hotel, $receptionist, $roles->system($hotel, SystemRole::Reception));

            if ($key === 'lodge') {
                $this->firstClassLodgeRooms($hotel);
            }
        }
    }

    /**
     * A demo account with the password "password", e-mail already confirmed.
     */
    private function user(string $first, string $last, string $email, ?PlatformRole $role = null): User
    {
        $user = new User;
        $user->forceFill([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'platform_role' => $role,
        ])->save();

        return $user;
    }

    /**
     * The pilot hotel's real room set-up: 26 rooms in four categories.
     */
    private function firstClassLodgeRooms(Hotel $hotel): void
    {
        $categories = [
            ['STD', 'Standard', 'Standard', 10, 1, 2, 1, 3, 20000],
            ['EXE', 'Executive', 'Exécutive', 10, 1, 2, 1, 3, 25000],
            ['MSU', 'Mini Suite', 'Mini suite', 3, 3, 2, 2, 4, 35000],
            ['SUI', 'Suite', 'Suite', 3, 4, 3, 2, 4, 40000],
        ];

        app(TenantContext::class)->run($hotel, function () use ($categories): void {
            $number = 101;

            foreach ($categories as $order => [$code, $en, $fr, $count, $floor, $maxAdults, $maxChildren, $maxGuests, $rate]) {
                $category = RoomCategory::query()->create([
                    'code' => $code,
                    'name' => ['fr' => $fr, 'en' => $en],
                    'standard_occupancy' => 2,
                    'max_adults' => $maxAdults,
                    'max_children' => $maxChildren,
                    'max_occupancy' => $maxGuests,
                    'extra_bed_allowed' => $code !== 'STD',
                    'amenities' => ['air_conditioning', 'wifi', 'tv', 'private_bathroom', 'hot_water'],
                    'active' => true,
                    'sort_order' => $order,
                ]);

                // Reference rates from the CNH note (FCFA, no decimals).
                CategoryRate::query()->create([
                    'room_category_id' => $category->id,
                    'base_minor' => $rate,
                    'extra_adult_minor' => 5000,
                    'child_minor' => 2500,
                    'extra_bed_minor' => 5000,
                    'breakfast_mode' => BreakfastMode::Optional,
                    'breakfast_minor' => 3000,
                    'min_nights' => 1,
                ]);

                foreach (range(1, $count) as $ignored) {
                    Room::query()->create([
                        'room_category_id' => $category->id,
                        'number' => (string) $number++,
                        'floor' => (string) $floor,
                        'status' => RoomStatus::InService,
                    ]);
                }
            }
        });
    }
}
