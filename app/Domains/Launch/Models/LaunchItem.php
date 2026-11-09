<?php

declare(strict_types=1);

namespace App\Domains\Launch\Models;

use App\Models\User;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $key
 * @property string $area
 * @property array<string, string> $title
 * @property bool $required
 * @property string $owner_role
 * @property string $status open, done, not_needed
 * @property string|null $proof
 * @property string|null $done_by
 * @property CarbonImmutable|null $done_at
 * @property CarbonImmutable|null $due_on
 * @property-read User|null $doneBy
 */
class LaunchItem extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['title' => 'array', 'required' => 'boolean', 'done_at' => 'immutable_datetime', 'due_on' => DateOnly::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
