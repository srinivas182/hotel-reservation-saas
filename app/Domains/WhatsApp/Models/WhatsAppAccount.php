<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Domains\WhatsApp\AccountStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A hotel's connected WhatsApp Business number. The access token and the
 * two-step verification PIN are encrypted and never leave the server.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $waba_id
 * @property string $phone_number_id
 * @property string|null $display_phone
 * @property string|null $verified_name
 * @property string|null $quality_rating
 * @property string|null $messaging_limit
 * @property bool $coexistence
 * @property AccountStatus $status
 * @property string|null $token
 * @property string|null $pin
 * @property CarbonImmutable|null $token_checked_at
 * @property string|null $last_error
 * @property string|null $connected_by
 * @property CarbonImmutable|null $connected_at
 */
class WhatsAppAccount extends Model
{
    use BelongsToHotel, HasUlids;

    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'waba_id', 'phone_number_id', 'display_phone', 'verified_name', 'quality_rating',
        'messaging_limit', 'coexistence', 'status', 'token', 'pin', 'token_checked_at',
        'last_error', 'connected_by', 'connected_at',
    ];

    protected $hidden = ['token', 'pin'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coexistence' => 'boolean',
            'status' => AccountStatus::class,
            'token' => 'encrypted',
            'pin' => 'encrypted',
            'token_checked_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
        ];
    }
}
