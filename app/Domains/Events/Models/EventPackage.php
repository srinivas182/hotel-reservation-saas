<?php

declare(strict_types=1);

namespace App\Domains\Events\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * A package (priced per person) or an extra (per person, per unit or per
 * event) that quotes are built from.
 *
 * @property int $id
 * @property string $kind package, extra
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property int $price_minor
 * @property string $unit per_person, per_unit, per_event
 * @property bool $active
 * @property int $sort_order
 */
class EventPackage extends Model
{
    use BelongsToHotel;

    public const UNITS = ['per_person', 'per_unit', 'per_event'];

    protected $fillable = ['kind', 'name', 'description', 'price_minor', 'unit', 'active', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array', 'price_minor' => 'integer', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function localizedName(string $locale, string $fallback): string
    {
        return $this->name[$locale] ?? $this->name[$fallback] ?? (string) (array_values($this->name)[0] ?? '');
    }
}
