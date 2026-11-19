<?php

declare(strict_types=1);

namespace App\Domains\Arrivals\Models;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a guest answered to the arrival card the day before (Sprint G).
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $booking_id
 * @property CarbonImmutable|null $card_sent_at
 * @property string|null $card_how text, template
 * @property CarbonImmutable|null $pin_sent_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $declined_at
 * @property CarbonImmutable|null $question_at
 * @property string|null $eta_slot see ArrivalSlots
 * @property CarbonImmutable|null $eta_at
 * @property list<string>|null $extras see ArrivalExtras
 * @property CarbonImmutable|null $flagged_at
 * @property-read Booking $booking
 */
class ArrivalCheck extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_sent_at' => 'immutable_datetime',
            'pin_sent_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'question_at' => 'immutable_datetime',
            'eta_at' => 'immutable_datetime',
            'flagged_at' => 'immutable_datetime',
            'extras' => 'array',
        ];
    }

    public static function for(Booking $booking): self
    {
        return self::query()->firstOrCreate(['booking_id' => $booking->id]);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** confirmed, declined, waiting (card sent, no answer) or null (no card). */
    public function state(): ?string
    {
        return match (true) {
            $this->declined_at !== null => 'declined',
            $this->confirmed_at !== null => 'confirmed',
            $this->card_sent_at !== null => 'waiting',
            default => null,
        };
    }
}
