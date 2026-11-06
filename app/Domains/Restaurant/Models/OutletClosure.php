<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The outlet is closed on a date (all day, or one service).
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $outlet_id
 * @property CarbonImmutable $date
 * @property int|null $service_period_id
 * @property string|null $reason
 */
class OutletClosure extends Model
{
    use BelongsToHotel;

    protected $fillable = ['outlet_id', 'date', 'service_period_id', 'reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => DateOnly::class];
    }
}
