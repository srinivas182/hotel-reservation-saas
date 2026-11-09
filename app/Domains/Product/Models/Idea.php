<?php

declare(strict_types=1);

namespace App\Domains\Product\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * F: a suggestion from hotels, voted on by everyone.
 *
 * @property int $id
 * @property string $title
 * @property string|null $body
 * @property string $category
 * @property string $status new, planned, in_progress, done, declined
 * @property int $votes_count
 * @property string|null $author_id
 * @property string|null $hotel_id
 * @property string|null $reply
 * @property int|null $merged_into_id
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $author
 */
class Idea extends Model
{
    public const STATUSES = ['new', 'planned', 'in_progress', 'done', 'declined'];

    public const CATEGORIES = ['whatsapp', 'assistant', 'bookings', 'restaurant', 'events', 'billing', 'other'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['votes_count' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasMany<IdeaVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(IdeaVote::class);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'planned' => __('Planned'),
            'in_progress' => __('Being built'),
            'done' => __('Available'),
            'declined' => __('Not planned'),
            default => __('Under review'),
        };
    }
}
