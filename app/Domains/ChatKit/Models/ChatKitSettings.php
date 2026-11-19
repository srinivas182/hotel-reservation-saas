<?php

declare(strict_types=1);

namespace App\Domains\ChatKit\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * The hotel's WhatsApp kit settings (Sprint H): the colour of posters and
 * button, the button's bubble text, the hours shown as "online", the logo.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $color #rrggbb
 * @property array<string, string>|null $bubble per language
 * @property string $online_from HH:MM
 * @property string $online_until HH:MM
 * @property string|null $logo_path
 */
class ChatKitSettings extends Model
{
    use BelongsToHotel;

    protected $table = 'chat_kits';

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['bubble' => 'array'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }
}
