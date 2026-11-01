<?php

declare(strict_types=1);

namespace App\Domains\Availability\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $hotel_id
 * @property string $room_category_id
 * @property CarbonImmutable $date
 * @property int $held_back
 * @property int $booked
 * @property bool $stop_sell
 */
class InventoryDay extends Model
{
    use BelongsToHotel;

    protected $fillable = ['room_category_id', 'date', 'held_back', 'booked', 'stop_sell'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'held_back' => 'integer',
            'booked' => 'integer',
            'stop_sell' => 'boolean',
        ];
    }
}
