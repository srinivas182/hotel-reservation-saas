<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\Guest;
use App\Domains\Orders\OrderDelivery;
use App\Domains\Orders\OrderPayment;
use App\Domains\Orders\OrderSource;
use App\Domains\Orders\OrderStatus;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $hotel_id
 * @property string $outlet_id
 * @property string|null $booking_id
 * @property string|null $guest_id
 * @property string|null $conversation_id
 * @property string $reference
 * @property OrderStatus $status
 * @property OrderSource $source
 * @property OrderDelivery $delivery
 * @property string|null $room_number
 * @property string|null $location_note
 * @property OrderPayment $payment
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property string $currency
 * @property int|null $eta_minutes
 * @property string|null $notes
 * @property string|null $decline_reason
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read Outlet|null $outlet
 * @property-read Guest|null $guest
 * @property-read Booking|null $booking
 */
class Order extends Model
{
    use BelongsToHotel, HasUlids;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'source' => OrderSource::class,
            'delivery' => OrderDelivery::class,
            'payment' => OrderPayment::class,
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'eta_minutes' => 'integer',
            'notes' => 'encrypted',
            'accepted_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('id');
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

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
