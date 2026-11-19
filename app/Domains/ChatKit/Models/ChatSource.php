<?php

declare(strict_types=1);

namespace App\Domains\ChatKit\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A poster, button or link that brings guests to the hotel's WhatsApp
 * (Sprint H). Its short code ends the pre-typed greeting ("… #R1"), so the
 * first message says where the guest came from; the code is then removed.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $code
 * @property string $kind poster, button, link, google, facebook, instagram, other
 * @property string $name
 * @property string $intent see ChatKit::INTENTS
 * @property bool $active
 * @property int $clicks
 * @property CarbonImmutable|null $last_clicked_at
 */
class ChatSource extends Model
{
    use BelongsToHotel;

    public const KINDS = ['poster', 'button', 'link', 'google', 'facebook', 'instagram', 'other'];

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'clicks' => 'integer', 'last_clicked_at' => 'immutable_datetime'];
    }
}
