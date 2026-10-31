<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Hotel>
 */
class HotelFactory extends Factory
{
    protected $model = Hotel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Hotel';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'legal_name' => null,
            'country_code' => 'CI',
            'timezone' => 'Africa/Abidjan',
            'currency' => 'XOF',
            'default_locale' => 'fr',
            'status' => HotelStatus::Active,
            'owner_user_id' => null,
        ];
    }

    public function status(HotelStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (): array => ['owner_user_id' => $owner->getKey()]);
    }
}
