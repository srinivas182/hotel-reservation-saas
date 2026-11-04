<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\Guest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booking a guest asked for on WhatsApp, waiting for reception. Its rooms
 * are held until it is decided or expires.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $reference
 * @property string|null $conversation_id
 * @property string $guest_id
 * @property BookingRequestStatus $status
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
 * @property string|null $inventory_hold_id
 * @property CarbonImmutable $expires_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decline_reason
 * @property string|null $booking_id
 * @property CarbonImmutable|null $created_at
 */
class BookingRequest extends Model
{
    use BelongsToHotel, HasUlids;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingRequestStatus::class,
            'check_in' => DateOnly::class,
            'check_out' => DateOnly::class,
            'children_ages' => 'array',
            'breakfast' => 'boolean',
            'extra_bed' => 'boolean',
            'price_snapshot' => 'array',
            'total_minor' => 'integer',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function stay(): StayRequest
    {
        return new StayRequest(
            categoryId: $this->room_category_id,
            checkIn: $this->check_in,
            checkOut: $this->check_out,
            adults: $this->adults,
            childAges: $this->children_ages,
            rooms: $this->rooms,
            breakfast: $this->breakfast,
            extraBed: $this->extra_bed,
        );
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
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
