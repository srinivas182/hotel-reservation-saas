<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email\Models;

use App\Domains\Messaging\Email\EmailCategory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log of every email the platform tried to send, kept for 90 days.
 *
 * Not scoped by BelongsToHotel: platform emails have no hotel. Hotel-facing
 * queries always filter by the current hotel explicitly.
 *
 * @property string $id
 * @property string|null $hotel_id
 * @property EmailCategory $category
 * @property list<string> $recipients
 * @property string|null $subject
 * @property int|null $mail_provider_id
 * @property string $status
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property CarbonInterface|null $created_at
 */
class EmailMessage extends Model
{
    use HasUlids, MassPrunable;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    public const RETENTION_DAYS = 90;

    protected $fillable = [
        'hotel_id', 'category', 'recipients', 'subject', 'mail_provider_id',
        'status', 'provider_message_id', 'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => EmailCategory::class,
            'recipients' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MailProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(MailProvider::class, 'mail_provider_id');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
