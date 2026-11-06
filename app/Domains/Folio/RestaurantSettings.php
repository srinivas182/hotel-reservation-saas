<?php

declare(strict_types=1);

namespace App\Domains\Folio;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * Room charges and payment methods of the current hotel.
 *
 * @property int $id
 * @property string $hotel_id
 * @property bool $room_charge_enabled
 * @property int|null $room_charge_order_limit_minor
 * @property int|null $room_charge_stay_limit_minor
 * @property bool $accept_outside_orders
 * @property list<string>|null $payment_methods
 */
class RestaurantSettings extends Model
{
    use BelongsToHotel;

    public const METHODS = ['cash', 'orange_money', 'mtn_momo', 'moov_money', 'wave', 'card', 'bank_transfer'];

    protected $table = 'restaurant_settings';

    protected $fillable = ['room_charge_enabled', 'room_charge_order_limit_minor', 'room_charge_stay_limit_minor', 'accept_outside_orders', 'payment_methods'];

    protected $attributes = ['room_charge_enabled' => true, 'accept_outside_orders' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'room_charge_enabled' => 'boolean',
            'room_charge_order_limit_minor' => 'integer',
            'room_charge_stay_limit_minor' => 'integer',
            'accept_outside_orders' => 'boolean',
            'payment_methods' => 'array',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->payment_methods !== null && $this->payment_methods !== [] ? $this->payment_methods : ['cash', 'orange_money', 'card'];
    }

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'cash' => __('Cash'),
            'orange_money' => 'Orange Money',
            'mtn_momo' => 'MTN MoMo',
            'moov_money' => 'Moov Money',
            'wave' => 'Wave',
            'card' => __('Card'),
            'bank_transfer' => __('Bank transfer'),
            default => $method,
        };
    }
}
