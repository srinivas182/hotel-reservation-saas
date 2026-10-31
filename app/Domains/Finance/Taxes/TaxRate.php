<?php

declare(strict_types=1);

namespace App\Domains\Finance\Taxes;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A tax a hotel charges: VAT, tourist tax, service charge.
 *
 * Percentages are stored in basis points (18% = 1800); fixed amounts in
 * minor units of the hotel's currency.
 *
 * @property string $id
 * @property string $hotel_id
 * @property array<string, string> $name
 * @property TaxType $type
 * @property int|null $percentage_bps
 * @property int|null $amount_minor
 * @property bool $inclusive
 * @property list<string> $applies_to
 * @property bool $active
 * @property CarbonInterface|null $effective_from
 * @property CarbonInterface|null $effective_to
 * @property int $sort_order
 */
class TaxRate extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = [
        'name', 'type', 'percentage_bps', 'amount_minor', 'inclusive',
        'applies_to', 'active', 'effective_from', 'effective_to', 'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'type' => TaxType::class,
            'percentage_bps' => 'integer',
            'amount_minor' => 'integer',
            'inclusive' => 'boolean',
            'applies_to' => 'array',
            'active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'sort_order' => 'integer',
        ];
    }

    public function localizedName(string $locale, string $fallback): string
    {
        return $this->name[$locale] ?? $this->name[$fallback] ?? (string) (array_values($this->name)[0] ?? '');
    }

    public function appliesTo(TaxScope $scope): bool
    {
        return in_array($scope->value, $this->applies_to, true);
    }

    public function isEffectiveOn(CarbonInterface $date): bool
    {
        return $this->active
            && ($this->effective_from === null || $this->effective_from->startOfDay()->lte($date))
            && ($this->effective_to === null || $this->effective_to->endOfDay()->gte($date));
    }
}
