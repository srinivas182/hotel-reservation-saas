<?php

declare(strict_types=1);

namespace App\Domains\Rooms\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A type of room the hotel sells (rooms are sold by category; the physical
 * room is assigned at check-in).
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $code
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property int $standard_occupancy
 * @property int $max_adults
 * @property int $max_children
 * @property int $max_occupancy
 * @property bool $extra_bed_allowed
 * @property list<string>|null $amenities
 * @property bool $active
 * @property int $sort_order
 */
class RoomCategory extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = [
        'code', 'name', 'description', 'standard_occupancy', 'max_adults', 'max_children',
        'max_occupancy', 'extra_bed_allowed', 'amenities', 'active', 'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'amenities' => 'array',
            'standard_occupancy' => 'integer',
            'max_adults' => 'integer',
            'max_children' => 'integer',
            'max_occupancy' => 'integer',
            'extra_bed_allowed' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function localizedName(string $locale, string $fallback): string
    {
        return $this->name[$locale] ?? $this->name[$fallback] ?? (string) (array_values($this->name)[0] ?? $this->code);
    }
}
