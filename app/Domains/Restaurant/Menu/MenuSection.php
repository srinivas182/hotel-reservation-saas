<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Menu;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $hotel_id
 * @property string $outlet_id
 * @property array<string, string> $name
 * @property int $sort_order
 */
class MenuSection extends Model
{
    use BelongsToHotel;

    protected $fillable = ['outlet_id', 'name', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['name' => 'array', 'sort_order' => 'integer'];
    }

    public function localizedName(string $locale, string $fallback): string
    {
        return $this->name[$locale] ?? $this->name[$fallback] ?? (string) (array_values($this->name)[0] ?? '');
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
