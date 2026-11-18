<?php

declare(strict_types=1);

namespace App\Domains\Workspace\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One task done on one hotel day, and by whom.
 *
 * @property int $id
 * @property string $hotel_id
 * @property int $shift_checklist_id
 * @property CarbonImmutable $day
 * @property string $item_key
 * @property string|null $user_id
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $user
 */
class ShiftChecklistTick extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['shift_checklist_id', 'day', 'item_key', 'user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day' => DateOnly::class, 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
