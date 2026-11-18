<?php

declare(strict_types=1);

namespace App\Domains\Push\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone or browser of a staff member that receives app notifications.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $p256dh
 * @property string $auth
 * @property string|null $device
 * @property CarbonImmutable|null $last_success_at
 * @property int $failures
 * @property CarbonImmutable|null $created_at
 */
class PushSubscription extends Model
{
    use BelongsToHotel;

    /** After this many failed sends in a row the subscription is removed. */
    public const MAX_FAILURES = 5;

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'device', 'last_success_at', 'failures'];

    protected $hidden = ['endpoint', 'p256dh', 'auth'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'p256dh' => 'encrypted', 'auth' => 'encrypted', 'last_success_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
