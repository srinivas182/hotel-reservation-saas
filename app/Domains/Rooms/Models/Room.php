<?php

declare(strict_types=1);

namespace App\Domains\Rooms\Models;

use App\Domains\Rooms\RoomStatus;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical room.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $room_category_id
 * @property string $number
 * @property string|null $floor
 * @property RoomStatus $status
 * @property string|null $notes
 */
class Room extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = ['room_category_id', 'number', 'floor', 'status', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => RoomStatus::class];
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    /**
     * @return HasMany<RoomClosure, $this>
     */
    public function closures(): HasMany
    {
        return $this->hasMany(RoomClosure::class);
    }
}
