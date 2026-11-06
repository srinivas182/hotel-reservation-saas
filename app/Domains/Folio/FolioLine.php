<?php

declare(strict_types=1);

namespace App\Domains\Folio;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a stay's bill. Never edited or deleted: mistakes are corrected
 * with a new line of the opposite sign.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $booking_id
 * @property string $type charge, payment, correction
 * @property string $source manual, order
 * @property string|null $source_id
 * @property string $description
 * @property int $quantity
 * @property int $amount_minor
 * @property int $tax_minor
 * @property string|null $method
 * @property string|null $reference
 * @property string|null $reason
 * @property int|null $corrects_line_id
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $author
 */
class FolioLine extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['booking_id', 'type', 'source', 'source_id', 'description', 'quantity', 'amount_minor', 'tax_minor', 'method', 'reference', 'reason', 'corrects_line_id', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'amount_minor' => 'integer', 'tax_minor' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
