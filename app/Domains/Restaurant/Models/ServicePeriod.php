<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service on one weekday (e.g. Friday dinner 19:00–22:30).
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $outlet_id
 * @property int $weekday 1 = Monday … 7 = Sunday
 * @property array<string, string> $name
 * @property string $starts_at HH:MM
 * @property string $ends_at HH:MM
 * @property int $slot_minutes
 * @property int $last_seating_minutes
 */
class ServicePeriod extends Model
{
    use BelongsToHotel;

    protected $table = 'outlet_service_periods';

    protected $fillable = ['outlet_id', 'weekday', 'name', 'starts_at', 'ends_at', 'slot_minutes', 'last_seating_minutes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['name' => 'array', 'weekday' => 'integer', 'slot_minutes' => 'integer', 'last_seating_minutes' => 'integer'];
    }

    public function localizedName(string $locale, string $fallback): string
    {
        return $this->name[$locale] ?? $this->name[$fallback] ?? (string) (array_values($this->name)[0] ?? '');
    }

    /**
     * The booking times this service offers, as HH:MM.
     *
     * @return list<string>
     */
    public function slots(): array
    {
        $start = Minutes::of($this->starts_at);
        $end = Minutes::of($this->ends_at);

        if ($end <= $start) {
            $end = 24 * 60 - 1; // services end by midnight (validated when saved)
        }

        $last = $end - $this->last_seating_minutes;
        $slots = [];

        for ($t = $start; $t <= $last; $t += max(5, $this->slot_minutes)) {
            $slots[] = Minutes::format($t);
        }

        return $slots;
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
