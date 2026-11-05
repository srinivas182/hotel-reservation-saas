<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An internal note on a conversation. Never sent to the guest.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $conversation_id
 * @property string|null $user_id
 * @property string $body
 * @property CarbonImmutable|null $created_at
 */
class ConversationNote extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'user_id', 'body'];

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
        return $this->belongsTo(User::class, 'user_id');
    }
}
