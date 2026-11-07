<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A company that issues invoices to hotels (platform level, e.g. CNH's
 * company in Côte d'Ivoire).
 *
 * @property int $id
 * @property string $name
 * @property string $legal_name
 * @property string $country_code
 * @property string $address
 * @property string|null $registration
 * @property string|null $tax_id
 * @property string $email
 * @property string|null $phone
 * @property string $invoice_prefix
 * @property list<array{name: string, rate_bps: int}>|null $tax_rules
 * @property bool $prices_include_tax
 * @property int $payment_terms_days
 * @property string|null $payment_instructions
 * @property string|null $footer
 * @property bool $is_default
 * @property bool $active
 */
class BillingEntity extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['payment_terms_days' => 7, 'prices_include_tax' => false, 'is_default' => false, 'active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tax_rules' => 'array', 'prices_include_tax' => 'boolean', 'payment_terms_days' => 'integer', 'is_default' => 'boolean', 'active' => 'boolean'];
    }

    public static function default(): ?self
    {
        return self::query()->where('active', true)->orderByDesc('is_default')->orderBy('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->only(['name', 'legal_name', 'country_code', 'address', 'registration', 'tax_id', 'email', 'phone', 'payment_instructions', 'footer']);
    }
}
