<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp alert sent (or skipped) to a staff member.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string|null $user_id
 * @property string $kind
 * @property string|null $subject_id
 * @property string $how text, template, skipped, failed
 * @property string|null $reason
 * @property string|null $wamid
 * @property CarbonImmutable|null $created_at
 */
class StaffAlert extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'kind', 'subject_id', 'how', 'reason', 'wamid'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
