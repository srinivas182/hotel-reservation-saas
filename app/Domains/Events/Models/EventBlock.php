<?php

declare(strict_types=1);

namespace App\Domains\Events\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * A time block events are sold by (morning, afternoon, evening, full day).
 * A block covering others (full day = morning + afternoon) takes them all.
 *
 * @property int $id
 * @property string $hotel_id
 * @property array<string, string> $name
 * @property string $starts_at
 * @property string $ends_at
 * @property list<int>|null $covers
 * @property int $sort_order
 */
class EventBlock extends Model
{
    use BelongsToHotel;

    protected $fillable = ['name', 'starts_at', 'ends_at', 'covers', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['name' => 'array', 'covers' => 'array', 'sort_order' => 'integer'];
    }

    public function localizedName(string $locale, string $fallback): string
    {
        return $this->name[$locale] ?? $this->name[$fallback] ?? (string) (array_values($this->name)[0] ?? '');
    }

    /**
     * @return list<int>
     */
    public function footprint(): array
    {
        return array_values(array_unique([$this->id, ...array_map('intval', $this->covers ?? [])]));
    }
}
