<?php

declare(strict_types=1);

namespace App\Domains\Platform\Features;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $mode on, off, targeted
 * @property list<string>|null $countries
 * @property list<string>|null $plans
 * @property list<string>|null $hotels
 */
class FeatureFlag extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['countries' => 'array', 'plans' => 'array', 'hotels' => 'array'];
    }
}
