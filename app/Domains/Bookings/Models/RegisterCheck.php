<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The evening check that the dashboard matches the paper register.
 *
 * @property int $id
 * @property string $hotel_id
 * @property CarbonImmutable $night
 * @property string|null $checked_by
 * @property bool $matches
 * @property list<string> $rooms room numbers occupied that night when checked
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 */
class RegisterCheck extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['night', 'checked_by', 'matches', 'rooms', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'night' => DateOnly::class,
            'matches' => 'boolean',
            'rooms' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
