<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Models;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The deposit of one tentative booking (Sprint F): the guest's private
 * payment link, what was paid and how, the receipt, and any refund owed
 * after a cancellation. The link itself is a random token; only its hash
 * is stored.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $booking_id
 * @property string $token_hash
 * @property string $token encrypted; the link is /pay/{token}
 * @property string $status open, paid, expired, cancelled
 * @property string $purpose deposit (tentative booking) or balance (Sprint G: the rest of a confirmed stay, before arrival)
 * @property int $deposit_minor
 * @property string $currency
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $reminded_at
 * @property CarbonImmutable|null $sent_at
 * @property int|null $paid_minor
 * @property string|null $method folio payment method
 * @property string|null $paid_via online, manual
 * @property CarbonImmutable|null $paid_at
 * @property string|null $confirmed_by
 * @property string|null $receipt_number
 * @property int|null $folio_line_id
 * @property string|null $provider_reference
 * @property string|null $proof_message_id
 * @property string|null $refund_status due, done, kept
 * @property int|null $refund_due_minor
 * @property CarbonImmutable|null $refunded_at
 * @property string|null $refunded_by
 * @property string|null $refund_note
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read Booking $booking
 * @property-read User|null $confirmer
 */
class DepositLink extends Model
{
    use BelongsToHotel, HasUlids;

    public const STATUSES = ['open', 'paid', 'expired', 'cancelled'];

    protected $guarded = ['id', 'hotel_id'];

    protected $hidden = ['token_hash', 'token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'deposit_minor' => 'integer',
            'paid_minor' => 'integer',
            'refund_due_minor' => 'integer',
            'folio_line_id' => 'integer',
            'expires_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return HasMany<DepositAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(DepositAttempt::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
