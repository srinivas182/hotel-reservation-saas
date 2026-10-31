<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A user's membership of a hotel. Membership rows are read across hotels
 * (to list the hotels a user can switch to), so they are deliberately not
 * scoped by BelongsToHotel; access to them goes through HotelMembershipService.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $user_id
 * @property MembershipStatus $status
 * @property Carbon|null $joined_at
 */
class HotelMembership extends Pivot
{
    protected $table = 'hotel_user';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }
}
