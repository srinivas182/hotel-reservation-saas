<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Models;

use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\MessageStatus;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WhatsApp message. The text is encrypted in the database.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $conversation_id
 * @property string $direction "in" or "out"
 * @property string|null $wamid Meta's message ID
 * @property string|null $client_id our idempotency key for sends
 * @property string $type
 * @property string|null $body
 * @property array{media_id?: string, mime_type?: string, path?: string, size?: int, filename?: string|null, voice?: bool, latitude?: float, longitude?: float, error?: string|null, components?: list<array<string, mixed>>, category?: string}|null $media
 * @property string|null $template_name
 * @property string|null $template_language
 * @property MessageStatus $status
 * @property CarbonImmutable|null $status_at
 * @property int|null $error_code
 * @property string|null $error_text
 * @property string|null $sent_by
 * @property MessageSource $source
 * @property CarbonImmutable $sent_at
 * @property string|null $translation
 * @property string|null $draft_outcome sent_unchanged, edited, discarded
 * @property string|null $translation_language
 * @property string|null $transcript
 * @property string|null $transcript_language
 * @property CarbonImmutable|null $transcribed_at
 * @property string|null $transcript_error
 * @property string|null $language
 */
class Message extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = [
        'conversation_id', 'direction', 'wamid', 'client_id', 'type', 'body', 'media',
        'template_name', 'template_language', 'status', 'status_at', 'error_code',
        'error_text', 'sent_by', 'source', 'sent_at', 'draft_outcome', 'transcript', 'transcript_language', 'transcribed_at',
        'transcript_error', 'language',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'translation' => 'encrypted',
            'media' => 'array',
            'status' => MessageStatus::class,
            'status_at' => 'immutable_datetime',
            'error_code' => 'integer',
            'source' => MessageSource::class,
            'sent_at' => 'immutable_datetime',
            'transcript' => 'encrypted',
            'transcribed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function isInbound(): bool
    {
        return $this->direction === 'in';
    }
}
