<?php

declare(strict_types=1);

namespace App\Domains\Rates\Models;

use App\Domains\Rates\BreakfastMode;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Base prices of a room category, in minor units of the hotel currency.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $room_category_id
 * @property int $base_minor
 * @property int $extra_adult_minor
 * @property int $child_minor
 * @property int $extra_bed_minor
 * @property BreakfastMode $breakfast_mode
 * @property int $breakfast_minor
 * @property int $min_nights
 */
class CategoryRate extends Model
{
    use BelongsToHotel;

    protected $fillable = [
        'room_category_id', 'base_minor', 'extra_adult_minor', 'child_minor',
        'extra_bed_minor', 'breakfast_mode', 'breakfast_minor', 'min_nights',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_minor' => 'integer',
            'extra_adult_minor' => 'integer',
            'child_minor' => 'integer',
            'extra_bed_minor' => 'integer',
            'breakfast_mode' => BreakfastMode::class,
            'breakfast_minor' => 'integer',
            'min_nights' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }
}
