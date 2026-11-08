<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform-only note about a hotel (never shown to the hotel).
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $body
 * @property string|null $author_id
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $author
 */
class HotelNote extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['body' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
