<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's alert and summary choices for one hotel.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $user_id
 * @property string|null $alert_phone
 * @property list<string>|null $alerts
 * @property string|null $summary_time HH:MM, hotel time
 * @property list<string>|null $summary_channels whatsapp, email
 * @property CarbonImmutable|null $summary_sent_on
 * @property bool|null $approve_by_whatsapp null: on for those who manage the hotel (owners, managers)
 * @property CarbonImmutable|null $whatsapp_inbound_at last button tap
 * @property string|null $morning_time HH:MM hotel time, Sprint C morning summary; null: off
 * @property CarbonImmutable|null $morning_sent_on
 * @property bool $morning_quiet_days send one short line on a quiet day (false: nothing)
 * @property-read User|null $user
 */
class StaffAlertSettings extends Model
{
    use BelongsToHotel;

    /** @var array<string, mixed> */
    protected $attributes = ['morning_quiet_days' => true];

    protected $table = 'staff_alert_settings';

    protected $fillable = ['user_id', 'alert_phone', 'alerts', 'summary_time', 'summary_channels', 'summary_sent_on', 'approve_by_whatsapp', 'morning_time', 'morning_sent_on', 'morning_quiet_days'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alerts' => 'array',
            'summary_channels' => 'array',
            'summary_sent_on' => DateOnly::class,
            'approve_by_whatsapp' => 'boolean',
            'morning_sent_on' => DateOnly::class,
            'morning_quiet_days' => 'boolean',
            'whatsapp_inbound_at' => 'immutable_datetime',
        ];
    }

    public static function for(User $user): self
    {
        return self::query()->firstOrNew(['user_id' => $user->getKey()]);
    }

    public function wants(AlertKind $kind): bool
    {
        return $this->alert_phone !== null && ($kind === AlertKind::Test || in_array($kind->value, $this->alerts ?? [], true));
    }

    /**
     * Whether this person answers booking requests with WhatsApp buttons.
     * By default on for those who manage the hotel, off for reception.
     */
    public function approvesOnWhatsApp(User $user): bool
    {
        return $user->can('booking_requests.decide') && ($this->approve_by_whatsapp ?? $user->can('hotel.settings.manage'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
