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
 * @property string $support_access notify, ask_first
 * @property string|null $whatsapp_display_e164
 * @property string|null $app_color #rrggbb back-office colour (Sprint I); null = platform colour
 * @property string|null $email
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $map_url
 * @property float|null $latitude map pin, read from the Google Maps link
 * @property list<int>|null $simple_mode_roles roles that open the simple dashboard (Sprint E); null: reception
 * @property float|null $longitude
 * @property string $check_in_time
 * @property string $check_out_time
 * @property string|null $late_checkout_until
 * @property array<string, list<array{from: string, to: string}>>|null $staffed_hours
 * @property list<string>|null $enabled_locales
 * @property list<array{from: int, to: int, charge: string}>|null $child_age_bands
 * @property int $hold_minutes
 * @property string|null $early_departure_charge none, one_night (0.40.0); null on a row just created = none
 * @property string|null $booking_prefix
 * @property int $tentative_hours
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
        'support_access',
        'phone_e164', 'whatsapp_display_e164', 'email',
        'address_line1', 'address_line2', 'city', 'map_url', 'latitude', 'longitude', 'simple_mode_roles',
        'app_color',
        'check_in_time', 'check_out_time', 'late_checkout_until',
        'staffed_hours', 'enabled_locales',
        'child_age_bands', 'hold_minutes', 'booking_prefix', 'tentative_hours', 'early_departure_charge',
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
            'child_age_bands' => 'array',
            'hold_minutes' => 'integer',
            'tentative_hours' => 'integer',
            'latitude' => 'float',
            'simple_mode_roles' => 'array',
            'longitude' => 'float',
            'email_reply_to_token_expires_at' => 'datetime',
        ];
    }

    /**
     * The settings of the current hotel, created with defaults if missing.
     */
    public static function current(): self
    {
        $context = app(\App\Domains\Tenancy\TenantContext::class);

        // Many parts of one page read the settings: read once per web request.
        return \App\Support\RequestMemo::remember('hotel-settings|'.$context->id(), fn (): self => self::query()->firstOrCreate([], [
            'enabled_locales' => [$context->hotel()->default_locale],
        ]));
    }

    protected static function booted(): void
    {
        // Any change written elsewhere is read again by the next caller.
        $forget = function (): void {
            \App\Support\RequestMemo::forget('hotel-settings|');
        };
        static::saved($forget);
        static::deleted($forget);
    }
}
