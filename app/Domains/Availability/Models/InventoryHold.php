<?php

declare(strict_types=1);

namespace App\Domains\Availability\Models;

use App\Domains\Availability\HoldStatus;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rooms held while a booking is in progress.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $room_category_id
 * @property CarbonImmutable $check_in
 * @property CarbonImmutable $check_out
 * @property int $rooms
 * @property HoldStatus $status
 * @property CarbonImmutable $expires_at
 * @property string $source_type
 * @property string|null $source_id
 * @property string|null $note
 * @property string|null $created_by
 */
class InventoryHold extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = [
        'room_category_id', 'check_in', 'check_out', 'rooms', 'status',
        'expires_at', 'source_type', 'source_id', 'note', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in' => DateOnly::class,
            'check_out' => DateOnly::class,
            'rooms' => 'integer',
            'status' => HoldStatus::class,
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    /**
     * Holds that count against availability right now.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCounting(Builder $query): Builder
    {
        return $query->where('status', HoldStatus::Active->value)->where('expires_at', '>', now());
    }

    public function isCounting(): bool
    {
        return $this->status === HoldStatus::Active && $this->expires_at->isFuture();
    }
}
