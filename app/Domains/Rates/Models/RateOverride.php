<?php

declare(strict_types=1);

namespace App\Domains\Rates\Models;

use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A date-range exception to base rates: a different price, a minimum
 * stay, stop-sell or closed-to-arrival, optionally on some weekdays only.
 * When several overrides apply to a night, the most recently changed one
 * sets the price; restrictions from all of them apply.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string|null $room_category_id
 * @property CarbonInterface $from_date
 * @property CarbonInterface $to_date
 * @property list<int>|null $weekdays ISO weekdays, 1 = Monday
 * @property int|null $rate_minor
 * @property int|null $min_nights
 * @property bool $stop_sell
 * @property bool $closed_to_arrival
 * @property string $label
 * @property CarbonInterface|null $updated_at
 */
class RateOverride extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = [
        'room_category_id', 'from_date', 'to_date', 'weekdays', 'rate_minor',
        'min_nights', 'stop_sell', 'closed_to_arrival', 'label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_date' => DateOnly::class,
            'to_date' => DateOnly::class,
            'weekdays' => 'array',
            'rate_minor' => 'integer',
            'min_nights' => 'integer',
            'stop_sell' => 'boolean',
            'closed_to_arrival' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    public function appliesTo(string $categoryId, CarbonInterface $night): bool
    {
        if ($this->room_category_id !== null && $this->room_category_id !== $categoryId) {
            return false;
        }

        $date = $night->toDateString();

        if ($date < $this->from_date->toDateString() || $date > $this->to_date->toDateString()) {
            return false;
        }

        return $this->weekdays === null || $this->weekdays === []
            || in_array($night->isoWeekday(), array_map('intval', $this->weekdays), true);
    }
}
