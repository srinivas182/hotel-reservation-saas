<?php

declare(strict_types=1);

namespace App\Domains\Rates\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A discount code the hotel gives out (campaigns, companies, returning
 * guests). Percent: value is the percentage; fixed: value is an amount in
 * minor units of the hotel currency.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $code
 * @property string|null $note
 * @property string $kind percent, fixed
 * @property int $value
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property int|null $max_uses
 * @property int $used_count
 * @property int|null $min_nights
 * @property bool $active
 */
class Coupon extends Model
{
    use BelongsToHotel;

    public const KINDS = ['percent', 'fixed'];

    protected $fillable = ['code', 'note', 'kind', 'value', 'valid_from', 'valid_until', 'max_uses', 'min_nights', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'min_nights' => 'integer',
            'active' => 'boolean',
        ];
    }

    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $code) ?? '');
    }
}
