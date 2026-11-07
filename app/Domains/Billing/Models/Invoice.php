<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Tenancy\Models\Hotel;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice or credit note from a billing entity to a hotel. Never
 * deleted; corrected with a credit note. Not hotel-scoped: the platform
 * issues them (hotels read their own through the billing page).
 *
 * @property string $id
 * @property string $hotel_id
 * @property int $billing_entity_id
 * @property string $number
 * @property string $kind invoice, credit_note
 * @property string|null $credits_invoice_id
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $paid_minor
 * @property list<array{name: string, rate_bps: int, amount_minor: int}> $taxes
 * @property array<string, mixed> $issuer
 * @property array<string, mixed> $customer
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable $due_on
 * @property CarbonImmutable|null $paid_at
 * @property array<string, string>|null $reminders
 * @property string|null $reason
 * @property-read Hotel|null $hotel
 */
class Invoice extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer', 'paid_minor' => 'integer',
            'taxes' => 'array', 'issuer' => 'array', 'customer' => 'array', 'reminders' => 'array',
            'issued_on' => DateOnly::class, 'due_on' => DateOnly::class, 'paid_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): bool => false);
    }

    /**
     * What is still to pay, after payments and credit notes.
     */
    public function balance(): int
    {
        if ($this->kind === 'credit_note') {
            return 0;
        }

        $credited = (int) self::query()->where('credits_invoice_id', $this->id)->sum('total_minor');

        return max(0, $this->total_minor - $this->paid_minor - $credited);
    }

    public function status(): string
    {
        if ($this->kind === 'credit_note') {
            return 'credit_note';
        }

        return match (true) {
            $this->balance() === 0 => $this->paid_minor > 0 ? 'paid' : 'cancelled',
            $this->due_on->lt(CarbonImmutable::today()) => 'overdue',
            default => 'open',
        };
    }

    public function daysOverdue(): int
    {
        return $this->status() === 'overdue' ? (int) $this->due_on->diffInDays(CarbonImmutable::today()) : 0;
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<InvoicePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('received_on');
    }

    /**
     * @return BelongsTo<Hotel, $this>
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * @return BelongsTo<BillingEntity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class, 'billing_entity_id');
    }
}
