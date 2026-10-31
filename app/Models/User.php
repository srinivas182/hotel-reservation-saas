<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\Access\SystemRole;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Tenancy\Enums\MembershipStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\Models\HotelMembership;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person who signs in: hotel staff, hotel owners and MCS platform staff.
 * Users are not hotel-owned; their access to hotels is through memberships.
 *
 * @property string $id
 * @property string $first_name
 * @property string $last_name
 * @property-read string $name
 * @property string $email
 * @property string|null $phone_e164
 * @property string $preferred_locale
 * @property PlatformRole|null $platform_role
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['first_name', 'last_name', 'email', 'phone_e164', 'preferred_locale', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
#[Appends(['name'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUlids, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'platform_role' => PlatformRole::class,
        ];
    }

    /**
     * Full display name.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * @return BelongsToMany<Hotel, $this, HotelMembership, 'membership'>
     */
    public function hotels(): BelongsToMany
    {
        return $this->belongsToMany(Hotel::class)
            ->using(HotelMembership::class)
            ->as('membership')
            ->withPivot(['status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Hotel, $this, HotelMembership, 'membership'>
     */
    public function activeHotels(): BelongsToMany
    {
        return $this->hotels()->wherePivot('status', MembershipStatus::Active->value);
    }

    /**
     * @return HasMany<Hotel, $this>
     */
    public function ownedHotels(): HasMany
    {
        return $this->hasMany(Hotel::class, 'owner_user_id');
    }

    public function isPlatformStaff(): bool
    {
        return $this->platform_role !== null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->platform_role === PlatformRole::SuperAdmin;
    }

    /**
     * Owners and platform staff must use two-factor authentication.
     */
    public function requiresTwoFactor(): bool
    {
        return $this->isPlatformStaff()
            || $this->ownedHotels()->exists()
            || $this->holdsOwnerRoleAnywhere();
    }

    /**
     * Whether the user holds the Owner role in any hotel (all teams).
     */
    public function holdsOwnerRoleAnywhere(): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->where('roles.is_system', true)
            ->where('roles.name', SystemRole::Owner->value)
            ->exists();
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
