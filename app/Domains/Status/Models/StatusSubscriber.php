<?php

declare(strict_types=1);

namespace App\Domains\Status\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $email
 * @property string $locale
 * @property string|null $country_code
 * @property string $token
 * @property CarbonImmutable|null $confirmed_at
 */
class StatusSubscriber extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime'];
    }
}
