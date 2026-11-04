<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A hotel's assistant settings.
 *
 * @property int $id
 * @property string $hotel_id
 * @property bool $enabled
 * @property string|null $display_name
 * @property string $tone
 * @property list<string>|null $languages
 * @property array<string, string>|null $handover_messages
 * @property int $daily_budget_micro_usd
 * @property bool $kill_switch
 * @property CarbonImmutable|null $budget_alerted_on
 * @property int $request_hold_minutes
 */
class HotelAssistantSettings extends Model
{
    use BelongsToHotel;

    protected $table = 'hotel_assistant_settings';

    /** Defaults for a new row (the database defaults, available before saving). */
    protected $attributes = [
        'enabled' => false,
        'tone' => 'formal',
        'daily_budget_micro_usd' => 5_000_000,
        'kill_switch' => false,
        'request_hold_minutes' => 120,
    ];

    protected $fillable = [
        'enabled', 'display_name', 'tone', 'languages', 'handover_messages',
        'daily_budget_micro_usd', 'kill_switch', 'budget_alerted_on', 'request_hold_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'languages' => 'array',
            'handover_messages' => 'array',
            'daily_budget_micro_usd' => 'integer',
            'request_hold_minutes' => 'integer',
            'kill_switch' => 'boolean',
            'budget_alerted_on' => 'immutable_date',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }
}
