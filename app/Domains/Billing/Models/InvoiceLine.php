<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_minor
 * @property int $amount_minor
 * @property int|null $subscription_change_id
 */
class InvoiceLine extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_minor' => 'integer', 'amount_minor' => 'integer'];
    }
}
