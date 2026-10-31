<?php

declare(strict_types=1);

namespace App\Domains\Platform;

use App\Domains\Access\HotelRoles;
use App\Domains\Access\StaffService;
use App\Domains\Access\SystemRole;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform-admin operations on hotels. Hotel and membership changes are
 * audited by HotelObserver and HotelMembershipService.
 */
final class HotelAdministration
{
    public function __construct(
        private readonly StaffService $staff,
        private readonly HotelRoles $roles,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Hotel
    {
        return DB::transaction(function () use ($data): Hotel {
            $owner = $this->ownerFrom($data);

            $hotel = Hotel::create([
                ...$this->attributes($data),
                'slug' => $this->uniqueSlug(is_string($data['slug'] ?? null) ? $data['slug'] : (string) $data['name']),
                'status' => $data['status'] ?? HotelStatus::Trial->value,
                'owner_user_id' => $owner?->getKey(),
            ]);

            if ($owner !== null) {
                $this->staff->assign($hotel, $owner, $this->roles->system($hotel, SystemRole::Owner));
            }

            return $hotel;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Hotel $hotel, array $data): Hotel
    {
        return DB::transaction(function () use ($hotel, $data): Hotel {
            $owner = $this->ownerFrom($data);

            $hotel->update([
                ...$this->attributes($data),
                'slug' => is_string($data['slug'] ?? null) ? $data['slug'] : $hotel->slug,
                'status' => $data['status'],
                'owner_user_id' => $owner?->getKey(),
            ]);

            if ($owner !== null) {
                $this->staff->assign($hotel, $owner, $this->roles->system($hotel, SystemRole::Owner));
            }

            return $hotel;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? null,
            'country_code' => $data['country_code'],
            'timezone' => $data['timezone'],
            'currency' => $data['currency'],
            'default_locale' => $data['default_locale'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ownerFrom(array $data): ?User
    {
        $email = $data['owner_email'] ?? null;

        return is_string($email) ? User::query()->where('email', $email)->first() : null;
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'hotel';
        $slug = $base;
        $suffix = 2;

        while (Hotel::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
