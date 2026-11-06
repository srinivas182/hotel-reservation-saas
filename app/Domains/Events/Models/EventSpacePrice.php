<?php

declare(strict_types=1);

namespace App\Domains\Events\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event_space_id
 * @property int $event_block_id
 * @property int $price_minor
 */
class EventSpacePrice extends Model
{
    use BelongsToHotel;

    protected $fillable = ['event_space_id', 'event_block_id', 'price_minor'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['event_block_id' => 'integer', 'price_minor' => 'integer'];
    }
}
