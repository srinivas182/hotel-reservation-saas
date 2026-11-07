<?php

declare(strict_types=1);

namespace App\Domains\Events\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of an event's sheet for the teams. Each save is a new
 * version, so every change after confirmation stays visible.
 *
 * @property int $id
 * @property string $event_id
 * @property int $version
 * @property array{timings?: list<array{time: string, what: string}>, layout?: string|null, people?: int|null, menu?: string|null, equipment?: string|null, kitchen?: string|null, setup?: string|null, reception?: string|null} $content
 * @property bool $after_confirmation
 * @property string|null $changed_by
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $author
 */
class EventSheet extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['event_id', 'version', 'content', 'after_confirmation', 'changed_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'content' => 'array', 'after_confirmation' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
