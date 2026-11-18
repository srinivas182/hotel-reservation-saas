<?php

declare(strict_types=1);

namespace App\Domains\Workspace\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tasks for the start or end of a shift, written by the owner (Sprint E):
 * "count the cash", "check tomorrow's arrivals".
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $name
 * @property string $shift opening, closing
 * @property list<string> $items
 * @property bool $active
 * @property int $sort_order
 */
class ShiftChecklist extends Model
{
    use BelongsToHotel;

    public const SHIFTS = ['opening', 'closing'];

    public const MAX_ITEMS = 20;

    protected $fillable = ['name', 'shift', 'items', 'active', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['items' => 'array', 'active' => 'boolean'];
    }

    /**
     * @return HasMany<ShiftChecklistTick, $this>
     */
    public function ticks(): HasMany
    {
        return $this->hasMany(ShiftChecklistTick::class);
    }

    public static function itemKey(string $item): string
    {
        return hash('sha256', mb_strtolower(trim($item)));
    }
}
