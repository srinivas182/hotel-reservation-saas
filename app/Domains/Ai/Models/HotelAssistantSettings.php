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
 * @property int $request_escalate_minutes Sprint B: owners get unanswered requests after this many minutes (0: off)
 * @property bool $deposit_required
 * @property int $deposit_percent
 * @property int $deposit_hours
 * @property int $silence_minutes
 * @property bool $waiting_reaction 0.37.6: ⏳ on the guest's message while the assistant prepares its answer
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
 * @property bool $deposit_online Sprint F: send a mobile-money payment link when the hotel's merchant account is active
 * @property bool $deposit_allow_full Sprint F: the guest may pay the whole stay instead of the deposit
 * @property int|null $deposit_refund_days Sprint F: deposit refunded when cancelled at least this many days before arrival (null: never)
 * @property array<string, string>|null $arrival_directions Sprint G: landmark directions, per language
 * @property array<string, string>|null $arrival_tip Sprint G: moto-taxi or taxi tip, per language
 * @property string|null $reception_closes_at Sprint G: HH:MM; later arrivals get the night instructions
 * @property array<string, string>|null $night_arrival Sprint G: what to do when arriving after reception closes, per language
 * @property array<string, array{enabled: bool, price_minor: int|null}>|null $arrival_extras Sprint G: pickup, early check-in, extra bed
 * @property string|null $arrival_photo_path Sprint G: photo of the entrance, on the card
 * @property array<string, array<string, string>>|null $decision_messages
 * @property array<string, mixed> $bank_transfer 0.36: enabled, bank_name, account_holder, account_number, iban, swift, branch, note (encrypted)
 * @property bool $auto_confirm 0.39.0: requests with free rooms are confirmed without reception
 * @property int $auto_confirm_max_rooms 0.39.0: more rooms than this go to reception
 * @property int $auto_confirm_max_nights 0.39.0: longer stays go to reception
 * @property int $prepay_discount_percent 0.31: discount for paying the whole stay online before arrival (0: not offered)
 * @property string|null $pay_at_hotel_hold_until 0.31: HH:MM on the arrival day until which a pay-at-the-hotel room is kept
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
        'request_escalate_minutes' => 15,
        'deposit_required' => false,
        'deposit_percent' => 30,
        'deposit_hours' => 24,
        'deposit_online' => true,
        'deposit_allow_full' => true,
        'deposit_refund_days' => 2,
        'silence_minutes' => 15,
        'waiting_reaction' => true,
        'daily_alert_cap' => 50,
        'mode' => 'off',
        'auto_confirm' => false,
        'auto_confirm_max_rooms' => 3,
        'auto_confirm_max_nights' => 14,
    ];

    protected $fillable = [
        'enabled', 'display_name', 'tone', 'languages', 'handover_messages',
        'daily_budget_micro_usd', 'kill_switch', 'budget_alerted_on', 'request_hold_minutes', 'request_escalate_minutes',
        'deposit_required', 'deposit_percent', 'deposit_hours', 'deposit_instructions', 'deposit_online', 'deposit_allow_full', 'deposit_refund_days', 'arrival_directions', 'arrival_tip', 'reception_closes_at', 'night_arrival', 'arrival_extras', 'arrival_photo_path', 'decision_messages', 'silence_minutes', 'waiting_reaction', 'daily_alert_cap', 'stay_messages', 'review_url',
        'prepay_discount_percent', 'pay_at_hotel_hold_until', 'bank_transfer',
        'auto_confirm', 'auto_confirm_max_rooms', 'auto_confirm_max_nights',
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
            'deposit_online' => 'boolean',
            'deposit_allow_full' => 'boolean',
            'deposit_refund_days' => 'integer',
            'prepay_discount_percent' => 'integer',
            'auto_confirm' => 'boolean',
            'auto_confirm_max_rooms' => 'integer',
            'auto_confirm_max_nights' => 'integer',
            'bank_transfer' => \App\Support\Casts\RecoverableEncryptedArray::class,
            'arrival_directions' => 'array',
            'arrival_tip' => 'array',
            'night_arrival' => 'array',
            'arrival_extras' => 'array',
            'silence_minutes' => 'integer',
            'waiting_reaction' => 'boolean',
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

    /**
     * The mode in effect: automatic falls back to supervised while the
     * platform has the automatic-mode feature off for this hotel.
     */
    /** 0.36: the hotel takes bank transfers and has said where to send them. */
    public function takesBankTransfer(): bool
    {
        $bank = $this->bank_transfer;

        $account = trim((string) ($bank['account_number'] ?? '')).trim((string) ($bank['iban'] ?? ''));

        return (bool) ($bank['enabled'] ?? false) && $account !== '' && trim((string) ($bank['account_holder'] ?? '')) !== '';
    }

    public function effectiveMode(): AssistantMode
    {
        if ($this->mode === AssistantMode::Automatic && ! app(\App\Domains\Platform\Features\Features::class)->enabled(\App\Domains\Platform\Features\Feature::AutomaticMode)) {
            return AssistantMode::Supervised;
        }

        return $this->mode;
    }
}
