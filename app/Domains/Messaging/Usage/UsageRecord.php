<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Usage;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only ledger of metered usage (emails now; AI conversations,
 * voice minutes and WhatsApp templates later). Credits and limits (S13)
 * are calculated from this ledger.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $meter
 * @property int $quantity
 * @property string|null $source_type
 * @property string|null $source_id
 * @property CarbonInterface $occurred_at
 */
class UsageRecord extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['meter', 'quantity', 'source_type', 'source_id', 'occurred_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Usage records cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Usage records cannot be deleted.'));
    }
}
