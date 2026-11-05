<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\AssistantMode;
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
 * @property array<string, array{enabled?: bool, time?: string}>|null $stay_messages
 * @property string|null $review_url
 * @property AssistantMode $mode
 * @property string|null $privacy_url
 * @property list<string>|null $human_keywords
 * @property CarbonImmutable|null $readiness_override_at
 * @property string|null $readiness_override_reason
 * @property string|null $readiness_override_by
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
        'mode' => 'off',
    ];

    protected $fillable = [
        'enabled', 'display_name', 'tone', 'languages', 'handover_messages',
        'daily_budget_micro_usd', 'kill_switch', 'budget_alerted_on', 'request_hold_minutes',
        'deposit_required', 'deposit_percent', 'deposit_hours', 'deposit_instructions', 'decision_messages', 'silence_minutes', 'daily_alert_cap', 'stay_messages', 'review_url',
        'mode', 'privacy_url', 'human_keywords', 'readiness_override_at', 'readiness_override_reason', 'readiness_override_by',
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
            'stay_messages' => 'array',
            'mode' => AssistantMode::class,
            'human_keywords' => 'array',
            'readiness_override_at' => 'immutable_datetime',
            'deposit_instructions' => 'array',
            'decision_messages' => 'array',
            'kill_switch' => 'boolean',
            'budget_alerted_on' => 'immutable_date',
        ];
    }

    public const DEFAULT_HUMAN_KEYWORDS = ['réception', 'reception', 'humain', 'human', 'personne', 'person', 'agent', 'staff'];

    /**
     * @return list<string>
     */
    public function humanKeywords(): array
    {
        $words = $this->human_keywords ?? [];

        return $words !== [] ? $words : self::DEFAULT_HUMAN_KEYWORDS;
    }

    protected static function booted(): void
    {
        // "enabled" (S5) and "mode" (S8) stay in step, whichever is changed.
        static::saving(function (self $settings): void {
            if ($settings->isDirty('mode')) {
                $settings->enabled = $settings->mode !== AssistantMode::Off;
            } elseif ($settings->isDirty('enabled')) {
                $settings->mode = $settings->enabled ? AssistantMode::Automatic : AssistantMode::Off;
            }
        });
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }
}
