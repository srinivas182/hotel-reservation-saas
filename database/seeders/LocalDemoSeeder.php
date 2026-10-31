<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development data: one platform admin and two hotels, each with an
 * owner and a receptionist. All demo accounts use the password "password".
 * Owners and the admin are asked to set up two-factor on first sign-in.
 */
class LocalDemoSeeder extends Seeder
{
    public function run(HotelMembershipService $memberships): void
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

            $memberships->add($hotel, $owner);
            $memberships->add($hotel, $receptionist);
        }
    }
}
