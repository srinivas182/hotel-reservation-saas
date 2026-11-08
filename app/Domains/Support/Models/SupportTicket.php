<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hotel's request for help. Read by the hotel (its own) and the platform.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string|null $requester_id
 * @property string $subject
 * @property string $category
 * @property string $status open, waiting_hotel, solved
 * @property string $priority normal, high, urgent
 * @property string|null $assignee_id
 * @property array<string, mixed>|null $context
 * @property bool $notify_whatsapp
 * @property CarbonImmutable|null $first_response_due_at
 * @property CarbonImmutable|null $first_responded_at
 * @property CarbonImmutable|null $solved_at
 * @property string|null $rating good, bad
 * @property string|null $rating_comment
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $created_at
 * @property-read Hotel|null $hotel
 * @property-read User|null $requester
 * @property-read User|null $assignee
 */
class SupportTicket extends Model
{
    public const CATEGORIES = ['whatsapp', 'assistant', 'bookings', 'restaurant', 'events', 'billing', 'account', 'technical', 'other'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array', 'notify_whatsapp' => 'boolean', 'first_response_due_at' => 'immutable_datetime', 'first_responded_at' => 'immutable_datetime',
            'solved_at' => 'immutable_datetime', 'last_activity_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
        ];
    }

    public function late(): bool
    {
        return $this->first_responded_at === null && $this->status !== 'solved' && $this->first_response_due_at !== null && $this->first_response_due_at->isPast();
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Hotel, $this>
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'whatsapp' => __('WhatsApp'),
            'assistant' => __('Assistant'),
            'bookings' => __('Bookings'),
            'restaurant' => __('Restaurant and bar'),
            'events' => __('Events'),
            'billing' => __('Billing'),
            'account' => __('Account and team'),
            'technical' => __('Something does not work'),
            default => __('Other'),
        };
    }
}
