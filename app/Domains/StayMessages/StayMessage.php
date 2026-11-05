<?php

declare(strict_types=1);

namespace App\Domains\StayMessages;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A stay message sent (or skipped) for a booking. One per booking and kind.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $booking_id
 * @property string $kind
 * @property string $how text, template, skipped
 * @property string|null $reason
 * @property CarbonImmutable|null $created_at
 */
class StayMessage extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['booking_id', 'kind', 'how', 'reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
