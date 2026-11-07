<?php

declare(strict_types=1);

namespace App\Domains\Events\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $event_quote_id
 * @property string $kind
 * @property string $description
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $total_minor
 * @property int $position
 */
class EventQuoteLine extends Model
{
    use BelongsToHotel;

    protected $fillable = ['event_quote_id', 'kind', 'description', 'quantity', 'unit_price_minor', 'total_minor', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'total_minor' => 'integer', 'position' => 'integer'];
    }
}
