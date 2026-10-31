<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only hotel-owned model used to exercise BelongsToHotel before the
 * first real hotel-owned models arrive in S1.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $body
 */
class Note extends Model
{
    use BelongsToHotel;

    protected $table = 'fixture_notes';

    protected $fillable = ['body'];
}
