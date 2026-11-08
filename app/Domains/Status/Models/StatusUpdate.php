<?php

declare(strict_types=1);

namespace App\Domains\Status\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $status_notice_id
 * @property array<string, string> $body
 * @property string $state
 * @property CarbonImmutable|null $created_at
 */
class StatusUpdate extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['body' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
