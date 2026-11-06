<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Models;

use App\Domains\Restaurant\OutletType;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A restaurant, bar or terrace of the hotel.
 *
 * @property string $id
 * @property string $hotel_id
 * @property array<string, string> $name
 * @property OutletType $type
 * @property int $seats
 * @property int $default_duration_minutes
 * @property int|null $slot_arrival_limit
 * @property int $auto_confirm_max_party
 * @property bool $auto_confirm
 * @property bool $active
 * @property string $public_slug
 * @property int $sort_order
 */
class Outlet extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = ['name', 'type', 'seats', 'default_duration_minutes', 'slot_arrival_limit', 'auto_confirm_max_party', 'auto_confirm', 'active', 'public_slug', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'type' => OutletType::class,
            'seats' => 'integer',
            'default_duration_minutes' => 'integer',
            'slot_arrival_limit' => 'integer',
            'auto_confirm_max_party' => 'integer',
            'auto_confirm' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function localizedName(string $locale, string $fallback): string
    {
        return $this->name[$locale] ?? $this->name[$fallback] ?? (string) (array_values($this->name)[0] ?? '');
    }

    /**
     * @return HasMany<ServicePeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(ServicePeriod::class)->orderBy('weekday')->orderBy('starts_at');
    }

    /**
     * @return HasMany<OutletClosure, $this>
     */
    public function closures(): HasMany
    {
        return $this->hasMany(OutletClosure::class)->orderBy('date');
    }
}
