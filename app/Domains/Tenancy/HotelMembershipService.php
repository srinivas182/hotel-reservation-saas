<?php

declare(strict_types=1);

namespace App\Domains\Tenancy;

use App\Domains\Audit\Audit;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Enums\MembershipStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may work in which hotel. All membership reads and changes go
 * through this service so they are consistent and audited.
 */
final class HotelMembershipService
{
    /**
     * Hotels the user can currently work in, ordered by name.
     *
     * @return Collection<int, Hotel>
     */
    public function accessibleHotels(User $user): Collection
    {
        return $user->activeHotels()
            ->whereIn('hotels.status', $this->accessibleStatuses())
            ->orderBy('hotels.name')
            ->get();
    }

    public function accessibleHotel(User $user, string $hotelId): ?Hotel
    {
        return $user->activeHotels()
            ->whereIn('hotels.status', $this->accessibleStatuses())
            ->where('hotels.id', $hotelId)
            ->first();
    }

    public function defaultHotelFor(User $user): ?Hotel
    {
        return $this->accessibleHotels($user)->first();
    }

    public function add(Hotel $hotel, User $user): void
    {
        $hotel->members()->syncWithoutDetaching([
            $user->getKey() => ['status' => MembershipStatus::Active->value, 'joined_at' => now()],
        ]);

        Audit::record('membership.added', 'User added to hotel', subject: $hotel, properties: ['user_id' => $user->getKey()]);
    }

    public function suspend(Hotel $hotel, User $user): void
    {
        $hotel->members()->updateExistingPivot($user->getKey(), ['status' => MembershipStatus::Suspended->value]);

        Audit::record('membership.suspended', 'User suspended from hotel', subject: $hotel, properties: ['user_id' => $user->getKey()]);
    }

    public function remove(Hotel $hotel, User $user): void
    {
        $hotel->members()->detach($user->getKey());

        Audit::record('membership.removed', 'User removed from hotel', subject: $hotel, properties: ['user_id' => $user->getKey()]);
    }

    /**
     * @return list<string>
     */
    private function accessibleStatuses(): array
    {
        return array_values(array_map(
            fn (HotelStatus $status): string => $status->value,
            array_filter(HotelStatus::cases(), fn (HotelStatus $status): bool => $status->allowsAccess()),
        ));
    }
}
