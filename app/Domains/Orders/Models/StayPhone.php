<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * An extra number reception added to a stay (e.g. the guest's spouse).
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $booking_id
 * @property string $phone
 */
class StayPhone extends Model
{
    use BelongsToHotel;

    protected $fillable = ['booking_id', 'phone'];
}
