<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A sign-up waiting for its email to be confirmed; the hotel is created
 * only then.
 *
 * @property int $id
 * @property string $user_id
 * @property array{hotel_name: string, country_code: string, city: string, phone: string, rooms: int} $details
 * @property int|null $signup_code_id
 * @property string|null $ip
 * @property string|null $hotel_id
 * @property CarbonImmutable|null $completed_at
 */
class PendingSignup extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['details' => 'array', 'completed_at' => 'immutable_datetime'];
    }
}
