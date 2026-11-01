<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A hotel's guest. No identity documents are stored.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $phone_e164
 * @property string|null $email
 * @property string|null $preferred_locale
 * @property string|null $notes
 * @property bool $whatsapp_opt_in
 * @property bool $marketing_opt_in
 */
class Guest extends Model
{
    use BelongsToHotel, HasUlids, SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'phone_e164', 'email', 'preferred_locale', 'notes',
        'whatsapp_opt_in', 'whatsapp_opt_in_at', 'marketing_opt_in', 'marketing_opt_in_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'whatsapp_opt_in' => 'boolean',
            'whatsapp_opt_in_at' => 'datetime',
            'marketing_opt_in' => 'boolean',
            'marketing_opt_in_at' => 'datetime',
        ];
    }

    public function name(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
