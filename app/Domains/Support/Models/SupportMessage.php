<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $support_ticket_id
 * @property string|null $author_id
 * @property string $side hotel, support
 * @property bool $internal
 * @property string $body
 * @property list<string>|null $attachments
 * @property string $via
 * @property bool $ai_drafted
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $author
 */
class SupportMessage extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['body' => 'encrypted', 'attachments' => 'array', 'internal' => 'boolean', 'ai_drafted' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
