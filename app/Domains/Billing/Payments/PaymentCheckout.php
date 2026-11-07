<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments;

use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay online (an invoice or a credit pack).
 *
 * @property string $id
 * @property string $hotel_id
 * @property int $payment_gateway_id
 * @property string $kind invoice, pack
 * @property string|null $invoice_id
 * @property int|null $credit_pack_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $reference
 * @property string|null $provider_token
 * @property string $status pending, paid, failed
 * @property string|null $checkout_url
 * @property string|null $failure
 * @property CarbonImmutable|null $paid_at
 * @property string|null $created_by
 * @property-read PaymentGateway|null $gateway
 * @property-read Invoice|null $invoice
 * @property-read CreditPack|null $pack
 */
class PaymentCheckout extends Model
{
    use BelongsToHotel, HasUlids;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<PaymentGateway, $this>
     */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<CreditPack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(CreditPack::class, 'credit_pack_id');
    }
}
