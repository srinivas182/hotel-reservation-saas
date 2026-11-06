<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Models;

use App\Domains\Bookings\Models\Guest;
use App\Domains\Restaurant\TableBookingSource;
use App\Domains\Restaurant\TableBookingStatus;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $hotel_id
 * @property string $outlet_id
 * @property string $guest_id
 * @property string|null $conversation_id
 * @property string $reference
 * @property CarbonImmutable $date
 * @property string $time HH:MM
 * @property int $covers
 * @property int $duration_minutes
 * @property TableBookingStatus $status
 * @property TableBookingSource $source
 * @property string|null $notes
 * @property CarbonImmutable|null $expires_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decline_reason
 * @property string|null $guest_notified
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read Outlet|null $outlet
 * @property-read Guest|null $guest
 */
class TableBooking extends Model
{
    use BelongsToHotel, HasUlids;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'covers' => 'integer',
            'duration_minutes' => 'integer',
            'status' => TableBookingStatus::class,
            'source' => TableBookingSource::class,
            'notes' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function startMinutes(): int
    {
        return Minutes::of($this->time);
    }

    public function endMinutes(): int
    {
        return $this->startMinutes() + $this->duration_minutes;
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
