<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Menu;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\Models\Hotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dish or drink. "Not available today" stores today's date, so it resets
 * by itself the next morning (hotel time zone) with no scheduled job.
 *
 * @property int $id
 * @property string $hotel_id
 * @property int $menu_section_id
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property int $price_minor price as entered by the hotel
 * @property list<string>|null $labels
 * @property list<string>|null $allergens
 * @property list<string>|null $period_names service names it is served at (empty: all)
 * @property string|null $photo_path
 * @property CarbonImmutable|null $unavailable_on
 * @property bool $active
 * @property int $sort_order
 * @property-read MenuSection|null $section
 */
class MenuItem extends Model
{
    use BelongsToHotel;

    protected $fillable = ['menu_section_id', 'name', 'description', 'price_minor', 'labels', 'allergens', 'period_names', 'photo_path', 'unavailable_on', 'active', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'price_minor' => 'integer',
            'labels' => 'array',
            'allergens' => 'array',
            'period_names' => 'array',
            'unavailable_on' => DateOnly::class,
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function localizedName(string $locale, string $fallback): string
    {
        return $this->name[$locale] ?? $this->name[$fallback] ?? (string) (array_values($this->name)[0] ?? '');
    }

    public function localizedDescription(string $locale, string $fallback): ?string
    {
        $description = $this->description ?? [];

        return $description[$locale] ?? $description[$fallback] ?? null;
    }

    public function availableToday(Hotel $hotel): bool
    {
        return $this->active && ($this->unavailable_on === null || ! $this->unavailable_on->isSameDay(HotelClock::today($hotel)));
    }

    /**
     * Served at this service (by name, in any language), or at all services.
     *
     * @param  array<string, string>|null  $periodName
     */
    public function servedAt(?array $periodName): bool
    {
        if ($this->period_names === null || $this->period_names === [] || $periodName === null) {
            return true;
        }

        $names = array_map(fn (string $n): string => mb_strtolower(trim($n)), $this->period_names);

        foreach ($periodName as $name) {
            if (in_array(mb_strtolower(trim($name)), $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return BelongsTo<MenuSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(MenuSection::class, 'menu_section_id');
    }
}
