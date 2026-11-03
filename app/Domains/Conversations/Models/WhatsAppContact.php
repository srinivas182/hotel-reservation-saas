<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Models;

use App\Domains\Bookings\Models\Guest;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $hotel_id
 * @property string $wa_id WhatsApp ID (the phone number without "+")
 * @property string|null $profile_name
 * @property string|null $guest_id
 * @property CarbonImmutable|null $last_inbound_at
 */
class WhatsAppContact extends Model
{
    use BelongsToHotel, HasUlids;

    protected $table = 'whatsapp_contacts';

    protected $fillable = ['wa_id', 'profile_name', 'guest_id', 'last_inbound_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_inbound_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
