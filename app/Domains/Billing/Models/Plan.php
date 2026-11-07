<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan (platform catalogue).
 *
 * @property string $id
 * @property string $code
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property list<string> $modules
 * @property int|null $staff_limit
 * @property int $ai_credits
 * @property int $email_credits
 * @property array<string, array{monthly?: int, yearly?: int}> $prices
 * @property int $trial_days
 * @property bool $is_default
 * @property bool $active
 * @property int $sort_order
 */
class Plan extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array', 'description' => 'array', 'modules' => 'array', 'prices' => 'array',
            'staff_limit' => 'integer', 'ai_credits' => 'integer', 'email_credits' => 'integer', 'trial_days' => 'integer',
            'is_default' => 'boolean', 'active' => 'boolean', 'sort_order' => 'integer',
        ];
    }

    public function localizedName(string $locale): string
    {
        return $this->name[$locale] ?? $this->name['en'] ?? (string) (array_values($this->name)[0] ?? $this->code);
    }

    public function price(string $currency, string $interval): ?int
    {
        $price = $this->prices[$currency][$interval] ?? null;

        return $price === null ? null : (int) $price;
    }

    public function credits(\App\Domains\Billing\CreditKind $kind): int
    {
        return $kind === \App\Domains\Billing\CreditKind::Ai ? $this->ai_credits : $this->email_credits;
    }
}
