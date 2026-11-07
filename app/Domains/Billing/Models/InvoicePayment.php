<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $invoice_id
 * @property int $amount_minor
 * @property string $method
 * @property string|null $reference
 * @property CarbonImmutable $received_on
 * @property string|null $provider_reference
 */
class InvoicePayment extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'received_on' => DateOnly::class];
    }
}
