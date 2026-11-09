<?php

declare(strict_types=1);

namespace App\Domains\Product\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $idea_id
 * @property string $user_id
 * @property string|null $hotel_id
 */
class IdeaVote extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
