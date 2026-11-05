<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Models;

use App\Domains\Conversations\ConversationMode;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One WhatsApp conversation per guest contact.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string $whatsapp_contact_id
 * @property string $status
 * @property CarbonImmutable|null $last_message_at
 * @property CarbonImmutable|null $last_inbound_at
 * @property int $unread_count
 * @property ConversationMode $mode
 * @property bool $needs_attention
 * @property string|null $handover_reason
 * @property CarbonImmutable|null $handed_over_at
 * @property string|null $last_answered_message_id
 * @property CarbonImmutable|null $staff_replied_at
 * @property string|null $assigned_to
 * @property CarbonImmutable|null $assigned_at
 * @property CarbonImmutable|null $waiting_since
 * @property CarbonImmutable|null $holding_sent_at
 */
class Conversation extends Model
{
    use BelongsToHotel, HasUlids;

    /** WhatsApp's customer service window. */
    public const WINDOW_HOURS = 24;

    protected $fillable = ['whatsapp_contact_id', 'status', 'last_message_at', 'last_inbound_at', 'unread_count', 'mode', 'needs_attention', 'handover_reason', 'handed_over_at'];

    protected $attributes = ['mode' => 'assistant', 'needs_attention' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'immutable_datetime',
            'last_inbound_at' => 'immutable_datetime',
            'unread_count' => 'integer',
            'mode' => ConversationMode::class,
            'needs_attention' => 'boolean',
            'handed_over_at' => 'immutable_datetime',
            'staff_replied_at' => 'immutable_datetime',
            'assigned_at' => 'immutable_datetime',
            'waiting_since' => 'immutable_datetime',
            'holding_sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WhatsAppContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<ConversationNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ConversationNote::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Free-text replies are allowed only within 24 hours of the guest's
     * last message.
     */
    public function windowClosesAt(): ?CarbonImmutable
    {
        return $this->last_inbound_at?->addHours(self::WINDOW_HOURS);
    }

    public function windowOpen(): bool
    {
        $closes = $this->windowClosesAt();

        return $closes !== null && $closes->isFuture();
    }
}
