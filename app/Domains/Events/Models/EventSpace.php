<?php

declare(strict_types=1);

namespace App\Domains\Events\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A meeting room, function room or garden. A combined space (two rooms
 * opened into one) lists the spaces it is made of.
 *
 * @property string $id
 * @property string $hotel_id
 * @property array<string, string> $name
 * @property array<string, int> $capacities layout => people
 * @property list<string>|null $combines
 * @property bool $active
 * @property int $sort_order
 */
class EventSpace extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = ['name', 'capacities', 'combines', 'active', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['name' => 'array', 'capacities' => 'array', 'combines' => 'array', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function localizedName(string $locale, string $fallback): string
    {
        return $this->name[$locale] ?? $this->name[$fallback] ?? (string) (array_values($this->name)[0] ?? '');
    }

    public function capacity(?string $layout): int
    {
        if ($layout === null) {
            return (int) max([0, ...array_values($this->capacities)]);
        }

        return (int) ($this->capacities[$layout] ?? 0);
    }
}
