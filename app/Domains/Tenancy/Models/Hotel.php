<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Platform\Policies\HotelPolicy;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Enums\MembershipStatus;
use App\Domains\Tenancy\Observers\HotelObserver;
use App\Models\User;
use Database\Factories\HotelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A tenant of the platform. The hotel itself is not hotel-owned data:
 * it is the boundary that hotel-owned data is separated by.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $legal_name
 * @property string $country_code
 * @property string $timezone
 * @property string $currency
 * @property string $default_locale
 * @property HotelStatus $status
 * @property string|null $owner_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'slug', 'legal_name', 'country_code', 'timezone', 'currency', 'default_locale', 'status', 'owner_user_id'])]
#[UseFactory(HotelFactory::class)]
#[ObservedBy(HotelObserver::class)]
#[UsePolicy(HotelPolicy::class)]
class Hotel extends Model
{
    /** @use HasFactory<HotelFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => HotelStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsToMany<User, $this, HotelMembership, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(HotelMembership::class)
            ->as('membership')
            ->withPivot(['status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this, HotelMembership, 'membership'>
     */
    public function activeMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('status', MembershipStatus::Active->value);
    }
}
