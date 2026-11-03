<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Knowledge;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published information pack. The assistant only uses the latest one.
 *
 * @property int $id
 * @property string $hotel_id
 * @property int $version
 * @property array<string, list<array{section: string, question: string|null, content: string}>> $snapshot entries by locale
 * @property string|null $published_by
 * @property CarbonImmutable $published_at
 */
class KnowledgeVersion extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $fillable = ['version', 'snapshot', 'published_by', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
