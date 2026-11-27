<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 0.39.3: a guest's change or cancellation of an existing booking, asked on
 * WhatsApp, for reception to apply or decline on the Today page.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $booking_id
 * @property string|null $conversation_id
 * @property string $kind change, cancellation
 * @property string $status pending, applied, declined, withdrawn
 * @property array<string, mixed>|null $stay
 * @property int $current_total_minor
 * @property int|null $new_total_minor
 * @property string $currency
 * @property string $summary
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decline_reason
 * @property CarbonImmutable|null $created_at
 * @property-read Booking $booking
 * @property-read Conversation|null $conversation
 * @property-read User|null $decider
 */
class BookingChangeRequest extends Model
{
    use BelongsToHotel, HasUlids;

    public const CHANGE = 'change';

    public const CANCELLATION = 'cancellation';

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stay' => 'array',
            'current_total_minor' => 'integer',
            'new_total_minor' => 'integer',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function newStay(): ?StayRequest
    {
        if (! is_array($this->stay)) {
            return null;
        }

        return new StayRequest(
            categoryId: (string) $this->stay['category_id'],
            checkIn: CarbonImmutable::parse((string) $this->stay['check_in']),
            checkOut: CarbonImmutable::parse((string) $this->stay['check_out']),
            adults: (int) $this->stay['adults'],
            childAges: array_values(array_map('intval', (array) ($this->stay['children_ages'] ?? []))),
            rooms: (int) $this->stay['rooms'],
            breakfast: (bool) $this->stay['breakfast'],
            extraBed: (bool) ($this->stay['extra_bed'] ?? false),
        );
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
