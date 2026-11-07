<?php

declare(strict_types=1);

namespace App\Domains\Events\Models;

use App\Domains\Availability\Models\InventoryHold;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rooms set aside for an event's attendees at a group rate, until a cut-off
 * date. The rooms are an inventory hold that stops counting at the cut-off,
 * so unused rooms go back on sale by themselves.
 *
 * @property int $id
 * @property string $event_id
 * @property string $room_category_id
 * @property CarbonImmutable $check_in
 * @property CarbonImmutable $check_out
 * @property int $rooms
 * @property int $booked_rooms
 * @property int $rate_minor
 * @property CarbonImmutable $cutoff_date
 * @property string|null $inventory_hold_id
 * @property CarbonImmutable|null $released_at
 * @property-read RoomCategory|null $category
 * @property-read InventoryHold|null $hold
 * @property-read Event|null $event
 */
class RoomBlock extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in' => DateOnly::class,
            'check_out' => DateOnly::class,
            'cutoff_date' => DateOnly::class,
            'rooms' => 'integer',
            'booked_rooms' => 'integer',
            'rate_minor' => 'integer',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function remaining(): int
    {
        return $this->released_at !== null ? 0 : max(0, $this->rooms - $this->booked_rooms);
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    /**
     * @return BelongsTo<InventoryHold, $this>
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(InventoryHold::class, 'inventory_hold_id');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
