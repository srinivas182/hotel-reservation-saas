<?php

declare(strict_types=1);

namespace App\Domains\Workspace\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * How one person likes the dashboard in this hotel (Sprint E).
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $user_id
 * @property string|null $ui_mode simple, full; null: their role's default
 * @property bool $large_text
 * @property list<string>|null $tours_done
 */
class StaffPreference extends Model
{
    use BelongsToHotel;

    protected $fillable = ['user_id', 'ui_mode', 'large_text', 'tours_done'];

    /** @var array<string, mixed> */
    protected $attributes = ['large_text' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['large_text' => 'boolean', 'tours_done' => 'array'];
    }

    public static function for(User $user): self
    {
        return self::query()->firstOrNew(['user_id' => $user->getKey()]);
    }
}
