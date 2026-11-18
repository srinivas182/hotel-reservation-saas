<?php

declare(strict_types=1);

namespace App\Domains\StaffActions\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A decision a staff member made with a WhatsApp button, carried out
 * after the undo window (Sprint B).
 *
 * @property string $id
 * @property string $hotel_id
 * @property string|null $user_id
 * @property string $kind
 * @property string $subject_id
 * @property string $action
 * @property string|null $reason
 * @property string $status scheduled, running, done, undone, failed
 * @property string|null $phone
 * @property CarbonImmutable $execute_at
 * @property CarbonImmutable|null $executed_at
 * @property string|null $result
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $user
 */
class StaffAction extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = ['user_id', 'kind', 'subject_id', 'action', 'reason', 'status', 'phone', 'execute_at', 'executed_at', 'result'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['execute_at' => 'immutable_datetime', 'executed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The decision about this subject that is waiting or being carried out.
     */
    public static function inProgress(string $kind, string $subjectId): ?self
    {
        return self::query()->with('user')->where('kind', $kind)->where('subject_id', $subjectId)->whereIn('status', ['scheduled', 'running'])->latest()->first();
    }
}
