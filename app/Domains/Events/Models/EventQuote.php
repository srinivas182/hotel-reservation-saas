<?php

declare(strict_types=1);

namespace App\Domains\Events\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $hotel_id
 * @property string $event_id
 * @property int $version
 * @property string $status draft, sent, accepted, superseded
 * @property CarbonImmutable $valid_until
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property string|null $discount_reason
 * @property int $tax_minor
 * @property int $total_minor
 * @property string $currency
 * @property string|null $terms
 * @property string|null $token_hash
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $accepted_at
 * @property string|null $accepted_name
 * @property string|null $accepted_title
 * @property string|null $accepted_ip
 * @property string|null $accepted_via
 * @property-read Event|null $event
 */
class EventQuote extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'valid_until' => DateOnly::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<EventQuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(EventQuoteLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
