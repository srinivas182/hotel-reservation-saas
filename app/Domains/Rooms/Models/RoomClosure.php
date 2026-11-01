<?php

declare(strict_types=1);

namespace App\Domains\Rooms\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room taken out of sale for a date range. Both dates are nights the room
 * is closed (to_date is the last closed night).
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $room_id
 * @property CarbonInterface $from_date
 * @property CarbonInterface $to_date
 * @property string|null $reason
 */
class RoomClosure extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = ['room_id', 'from_date', 'to_date', 'reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
