<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Models;

use App\Domains\Bookings\BookingChannel;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stay: one guest, one room category, one or more rooms.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $reference
 * @property string $guest_id
 * @property BookingChannel $channel
 * @property string|null $external_reference
 * @property string|null $group_label
 * @property BookingStatus $status
 * @property string $room_category_id
 * @property CarbonImmutable $check_in
 * @property CarbonImmutable $check_out
 * @property int $rooms
 * @property int $adults
 * @property list<int|null> $children_ages
 * @property bool $breakfast
 * @property bool $extra_bed
 * @property array<string, mixed> $price_snapshot
 * @property int $total_minor
 * @property string $currency
 * @property bool $price_overridden
 * @property string|null $override_reason
 * @property string|null $payment_note
 * @property string|null $notes
 * @property CarbonImmutable|null $tentative_until
 * @property string|null $inventory_hold_id
 * @property string|null $created_by
 */
class Booking extends Model
{
    use BelongsToHotel, HasUlids;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => BookingChannel::class,
            'status' => BookingStatus::class,
            'check_in' => DateOnly::class,
            'check_out' => DateOnly::class,
            'children_ages' => 'array',
            'breakfast' => 'boolean',
            'extra_bed' => 'boolean',
            'price_snapshot' => 'array',
            'total_minor' => 'integer',
            'price_overridden' => 'boolean',
            'tentative_until' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'no_show_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    /**
     * @return HasMany<BookingRoom, $this>
     */
    public function bookingRooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class)->orderBy('position');
    }

    public function nights(): int
    {
        return (int) $this->check_in->diffInDays($this->check_out);
    }
}
