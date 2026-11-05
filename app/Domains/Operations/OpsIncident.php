<?php

declare(strict_types=1);

namespace App\Domains\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An operations problem noticed by the ops check, open until it clears.
 *
 * @property int $id
 * @property string $key
 * @property string $title
 * @property string|null $detail
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $last_notified_at
 * @property CarbonImmutable|null $resolved_at
 */
class OpsIncident extends Model
{
    public $timestamps = false;

    protected $fillable = ['key', 'title', 'detail', 'opened_at', 'last_notified_at', 'resolved_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime', 'last_notified_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
