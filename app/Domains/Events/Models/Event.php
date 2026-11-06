<?php

declare(strict_types=1);

namespace App\Domains\Events\Models;

use App\Domains\Events\EventStatus;
use App\Domains\Events\EventType;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $hotel_id
 * @property string $reference
 * @property string $client_name
 * @property string|null $company
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $guest_id
 * @property string|null $conversation_id
 * @property EventType $type
 * @property string $source
 * @property CarbonImmutable $date
 * @property int|null $event_block_id
 * @property string|null $event_space_id
 * @property string|null $layout
 * @property int $people
 * @property EventStatus $status
 * @property CarbonImmutable|null $option_until
 * @property bool $waitlisted
 * @property CarbonImmutable|null $waitlisted_at
 * @property string|null $notes
 * @property string|null $lost_reason
 * @property string|null $owner_user_id
 * @property CarbonImmutable|null $created_at
 * @property-read EventSpace|null $space
 * @property-read EventBlock|null $block
 * @property-read User|null $owner
 */
class Event extends Model
{
    use BelongsToHotel, HasUlids;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'status' => EventStatus::class,
            'date' => DateOnly::class,
            'event_block_id' => 'integer',
            'people' => 'integer',
            'option_until' => 'immutable_datetime',
            'waitlisted' => 'boolean',
            'waitlisted_at' => 'immutable_datetime',
            'notes' => 'encrypted',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether this event holds its space now (a live option or confirmed).
     */
    public function holdsSpace(): bool
    {
        return $this->event_space_id !== null && $this->event_block_id !== null && ! $this->waitlisted && (
            $this->status === EventStatus::Confirmed
            || ($this->status === EventStatus::Option && $this->option_until !== null && $this->option_until->isFuture())
        );
    }

    /**
     * @return BelongsTo<EventSpace, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(EventSpace::class, 'event_space_id');
    }

    /**
     * @return BelongsTo<EventBlock, $this>
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(EventBlock::class, 'event_block_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
