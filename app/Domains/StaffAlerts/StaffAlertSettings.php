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
 * @property-read User|null $user
 */
class StaffAlertSettings extends Model
{
    use BelongsToHotel;

    protected $table = 'staff_alert_settings';

    protected $fillable = ['user_id', 'alert_phone', 'alerts', 'summary_time', 'summary_channels', 'summary_sent_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alerts' => 'array',
            'summary_channels' => 'array',
            'summary_sent_on' => DateOnly::class,
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
