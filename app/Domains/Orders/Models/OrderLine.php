<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * A dish on an order. Name and price are copied when the order is placed,
 * so later menu changes never alter it.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $order_id
 * @property int|null $menu_item_id
 * @property string $name
 * @property int $quantity
 * @property int $unit_price_minor price the guest pays per item
 * @property int $total_minor
 */
class OrderLine extends Model
{
    use BelongsToHotel;

    protected $fillable = ['order_id', 'menu_item_id', 'name', 'quantity', 'unit_price_minor', 'total_minor'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'total_minor' => 'integer'];
    }
}
