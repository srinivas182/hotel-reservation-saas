<?php

declare(strict_types=1);

namespace App\Domains\Events\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $deposit_percent
 * @property int $option_days
 * @property int $quote_valid_days
 * @property array<string, string>|null $terms per language
 */
class EventSettings extends Model
{
    use BelongsToHotel;

    protected $table = 'event_settings';

    protected $fillable = ['deposit_percent', 'option_days', 'quote_valid_days', 'terms'];

    protected $attributes = ['deposit_percent' => 30, 'option_days' => 7, 'quote_valid_days' => 14];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['deposit_percent' => 'integer', 'option_days' => 'integer', 'quote_valid_days' => 'integer', 'terms' => 'array'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }
}
