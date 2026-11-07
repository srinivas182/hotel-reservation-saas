<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Defaults for new hotels in a country (set by the platform).
 *
 * @property int $id
 * @property string $country_code
 * @property array<string, string> $name
 * @property string $currency
 * @property string $timezone
 * @property string $locale
 * @property list<array{name: string, rate: float|int, inclusive: bool, applies_to: list<string>}> $tax_rules
 * @property string $check_in
 * @property string $check_out
 * @property list<string>|null $category_suggestions
 * @property bool $active
 */
class CountryTemplate extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['name' => 'array', 'tax_rules' => 'array', 'category_suggestions' => 'array', 'active' => 'boolean'];
    }

    public function localizedName(string $locale): string
    {
        return $this->name[$locale] ?? $this->name['en'] ?? $this->country_code;
    }
}
