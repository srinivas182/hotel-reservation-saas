<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Models;

use App\Domains\Rooms\Models\Room;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One room of a booking; the room number is given at check-in at the latest.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $booking_id
 * @property string|null $room_id
 * @property int $position
 */
class BookingRoom extends Model
{
    use BelongsToHotel;

    protected $fillable = ['booking_id', 'room_id', 'position'];

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
