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
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development data: one platform admin and two hotels, each with an
 * owner and a receptionist. All demo accounts use the password "password".
 * Owners and the admin are asked to set up two-factor on first sign-in.
 */
class LocalDemoSeeder extends Seeder
{
    public function run(StaffService $staff, HotelRoles $roles): void
    {
        User::factory()->platformAdmin(PlatformRole::SuperAdmin)->create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        $hotels = [
            ['First Class Lodge', 'first-class-lodge', 'CI', 'Africa/Abidjan', 'XOF', 'fr', 'lodge'],
            ['Demo City Hotel', 'demo-city-hotel', 'ZA', 'Africa/Johannesburg', 'ZAR', 'en', 'city'],
        ];

        foreach ($hotels as [$name, $slug, $country, $timezone, $currency, $locale, $key]) {
            $owner = User::factory()->create([
                'first_name' => 'Owner',
                'last_name' => $name,
                'email' => "owner.{$key}@example.com",
            ]);

            $receptionist = User::factory()->create([
                'first_name' => 'Reception',
                'last_name' => $name,
                'email' => "reception.{$key}@example.com",
            ]);

            $hotel = Hotel::factory()->ownedBy($owner)->create([
                'name' => $name,
                'slug' => $slug,
                'country_code' => $country,
                'timezone' => $timezone,
                'currency' => $currency,
                'default_locale' => $locale,
            ]);

            $staff->assign($hotel, $owner, $roles->system($hotel, SystemRole::Owner));
            $staff->assign($hotel, $receptionist, $roles->system($hotel, SystemRole::Reception));

            if ($key === 'lodge') {
                $this->firstClassLodgeRooms($hotel);
            }
        }
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
