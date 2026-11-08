<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform user helping inside a hotel's account for a limited time.
 * Platform-level (not hotel-scoped): it decides which hotel is entered.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $user_id
 * @property string $reason
 * @property string $mode read, write
 * @property string $status pending, active, ended, refused
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $expires_at
 * @property bool $extended
 * @property CarbonImmutable|null $ended_at
 * @property string|null $approved_by
 * @property CarbonImmutable|null $created_at
 * @property-read Hotel|null $hotel
 * @property-read User|null $user
 */
class SupportSession extends Model
{
    use HasUlids;

    public const MINUTES = 30;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'extended' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    public function isLive(): bool
    {
        return $this->status === 'active' && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Hotel, $this>
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
