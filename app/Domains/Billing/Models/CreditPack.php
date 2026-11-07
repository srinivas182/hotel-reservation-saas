<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\CreditKind;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property array<string, string> $name
 * @property CreditKind $kind
 * @property int $credits
 * @property array<string, int> $prices
 * @property int $valid_months
 * @property bool $active
 */
class CreditPack extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['name' => 'array', 'kind' => CreditKind::class, 'credits' => 'integer', 'prices' => 'array', 'valid_months' => 'integer', 'active' => 'boolean'];
    }

    public function localizedName(string $locale): string
    {
        return $this->name[$locale] ?? $this->name['en'] ?? (string) (array_values($this->name)[0] ?? $this->code);
    }
}
