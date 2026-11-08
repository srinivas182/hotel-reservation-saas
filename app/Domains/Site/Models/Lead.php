<?php

declare(strict_types=1);

namespace App\Domains\Site\Models;

use App\Domains\Onboarding\Models\SignupCode;
use App\Models\User;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A demo request from the website, followed by the sales team.
 *
 * @property int $id
 * @property string $name
 * @property string $hotel_name
 * @property string|null $country_code
 * @property string|null $city
 * @property int|null $rooms
 * @property string $whatsapp
 * @property string|null $email
 * @property string|null $message
 * @property string|null $contact_time
 * @property string $locale
 * @property string $status new, contacted, demo_done, trial, won, lost
 * @property string|null $lost_reason
 * @property string|null $owner_id
 * @property string|null $notes
 * @property CarbonImmutable|null $follow_up_on
 * @property int|null $signup_code_id
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $owner
 * @property-read SignupCode|null $code
 */
class Lead extends Model
{
    public const STATUSES = ['new', 'contacted', 'demo_done', 'trial', 'won', 'lost'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['message' => 'encrypted', 'notes' => 'encrypted', 'follow_up_on' => DateOnly::class, 'rooms' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<SignupCode, $this>
     */
    public function code(): BelongsTo
    {
        return $this->belongsTo(SignupCode::class, 'signup_code_id');
    }
}
