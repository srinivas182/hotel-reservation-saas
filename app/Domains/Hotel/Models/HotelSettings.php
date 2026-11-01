<?php

declare(strict_types=1);

namespace App\Domains\Hotel\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * Operational settings of a hotel (one row per hotel).
 *
 * @property int $id
 * @property string $hotel_id
 * @property string|null $phone_e164
 * @property string|null $whatsapp_display_e164
 * @property string|null $email
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $map_url
 * @property string $check_in_time
 * @property string $check_out_time
 * @property string|null $late_checkout_until
 * @property array<string, list<array{from: string, to: string}>>|null $staffed_hours
 * @property list<string>|null $enabled_locales
 * @property string|null $email_sender_name
 * @property string|null $email_reply_to
 * @property \Carbon\CarbonInterface|null $email_reply_to_verified_at
 * @property string|null $email_reply_to_token_hash
 * @property \Carbon\CarbonInterface|null $email_reply_to_token_expires_at
 */
class HotelSettings extends Model
{
    use BelongsToHotel;

    protected $table = 'hotel_settings';

    protected $fillable = [
        'phone_e164', 'whatsapp_display_e164', 'email',
        'address_line1', 'address_line2', 'city', 'map_url',
        'check_in_time', 'check_out_time', 'late_checkout_until',
        'staffed_hours', 'enabled_locales',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'staffed_hours' => 'array',
            'enabled_locales' => 'array',
            'email_reply_to_verified_at' => 'datetime',
            'email_reply_to_token_expires_at' => 'datetime',
        ];
    }

    /**
     * The settings of the current hotel, created with defaults if missing.
     */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'enabled_locales' => [app(\App\Domains\Tenancy\TenantContext::class)->hotel()->default_locale],
        ]);
    }
}
