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
 * @property bool $deposit_required
 * @property int $deposit_percent
 * @property int $deposit_hours
 * @property int $silence_minutes
 * @property int $daily_alert_cap
 * @property array<string, string>|null $deposit_instructions
 * @property array<string, array<string, string>>|null $decision_messages
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
        'deposit_required' => false,
        'deposit_percent' => 30,
        'deposit_hours' => 24,
        'silence_minutes' => 15,
        'daily_alert_cap' => 50,
    ];

    protected $fillable = [
        'enabled', 'display_name', 'tone', 'languages', 'handover_messages',
        'daily_budget_micro_usd', 'kill_switch', 'budget_alerted_on', 'request_hold_minutes',
        'deposit_required', 'deposit_percent', 'deposit_hours', 'deposit_instructions', 'decision_messages', 'silence_minutes', 'daily_alert_cap',
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
            'deposit_required' => 'boolean',
            'deposit_percent' => 'integer',
            'deposit_hours' => 'integer',
            'silence_minutes' => 'integer',
            'daily_alert_cap' => 'integer',
            'deposit_instructions' => 'array',
            'decision_messages' => 'array',
            'kill_switch' => 'boolean',
            'budget_alerted_on' => 'immutable_date',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }
}
