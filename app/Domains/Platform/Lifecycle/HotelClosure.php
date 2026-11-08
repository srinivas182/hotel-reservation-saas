<?php

declare(strict_types=1);

namespace App\Domains\Platform\Lifecycle;

use App\Domains\Tenancy\Models\Hotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hotel's closure: data kept 90 days (exportable), then deleted.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string|null $requested_by
 * @property string $by_role owner, platform
 * @property string|null $reason
 * @property string $previous_status
 * @property CarbonImmutable $closed_at
 * @property CarbonImmutable $delete_after
 * @property string|null $export_path
 * @property CarbonImmutable|null $exported_at
 * @property array<string, string>|null $reminders
 * @property CarbonImmutable|null $reopened_at
 * @property CarbonImmutable|null $purged_at
 * @property-read Hotel|null $hotel
 */
class HotelClosure extends Model
{
    public const KEEP_DAYS = 90;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closed_at' => 'immutable_datetime', 'delete_after' => 'immutable_datetime', 'exported_at' => 'immutable_datetime',
            'reminders' => 'array', 'reopened_at' => 'immutable_datetime', 'purged_at' => 'immutable_datetime',
        ];
    }

    public static function openFor(string $hotelId): ?self
    {
        return self::query()->where('hotel_id', $hotelId)->whereNull('reopened_at')->whereNull('purged_at')->latest('id')->first();
    }

    /**
     * @return BelongsTo<Hotel, $this>
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
