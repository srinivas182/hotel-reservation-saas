<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Models;

use App\Domains\Billing\Payments\Payable;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time the guest started paying (the deposit or the whole stay).
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $deposit_link_id
 * @property int $merchant_account_id
 * @property string $reference our transaction id at the provider
 * @property int $amount_minor
 * @property string $currency
 * @property string $status pending, paid, failed
 * @property string|null $provider_token
 * @property string|null $checkout_url
 * @property string|null $failure
 * @property-read DepositLink $link
 * @property-read MerchantAccount $account
 */
class DepositAttempt extends Model
{
    use BelongsToHotel, HasUlids;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<DepositLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(DepositLink::class, 'deposit_link_id');
    }

    /**
     * @return BelongsTo<MerchantAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class, 'merchant_account_id');
    }

    public function payable(): Payable
    {
        return new Payable($this->reference, $this->amount_minor, $this->currency, $this->provider_token);
    }
}
