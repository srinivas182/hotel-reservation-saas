<?php

declare(strict_types=1);

namespace App\Domains\Operations;

use App\Domains\Ai\Models\AiRun;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\Conversations\MessageStatus;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Messaging\Usage\UsageRecord;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The pilot's success measures for the current hotel over a period.
 */
final class PilotMetrics
{
    public const FAST_REPLY_SECONDS = 60;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function measure(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $messages = Message::query()
            ->where('sent_at', '>=', $from)->where('sent_at', '<', $to)
            ->whereNotIn('status', [MessageStatus::Draft->value, MessageStatus::Discarded->value])
            ->orderBy('conversation_id')->orderBy('sent_at')->orderBy('id')
            ->get(['id', 'conversation_id', 'direction', 'source', 'sent_at']);

        // Replies: for each guest message, the first hotel message after it.
        $inbound = 0;
        $turns = 0;
        $fast = 0;
        foreach ($messages->groupBy('conversation_id') as $thread) {
            $waiting = null;

            foreach ($thread as $message) {
                if ($message->direction === 'in') {
                    if ($waiting === null) {
                        $waiting = $message->sent_at;
                        $turns++;
                    }
                    $inbound++;

                    continue;
                }

                if ($waiting !== null) {
                    if ($waiting->diffInSeconds($message->sent_at) <= self::FAST_REPLY_SECONDS) {
                        $fast++;
                    }
                    $waiting = null;
                }
            }
        }

        $conversationIds = $messages->where('direction', 'in')->pluck('conversation_id')->unique();
        $conversations = Conversation::query()->whereIn('id', $conversationIds)->get(['id', 'handed_over_at']);
        $withoutStaff = $conversations->filter(fn (Conversation $c): bool => $c->handed_over_at === null || $c->handed_over_at->lt($from))->count();

        $requests = BookingRequest::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->get(['status', 'created_at', 'decided_at']);
        $decided = $requests->filter(fn (BookingRequest $r): bool => in_array($r->status, [BookingRequestStatus::Approved, BookingRequestStatus::Declined], true) && $r->decided_at !== null);
        $decisionMinutes = $decided->map(fn (BookingRequest $r): int => (int) $r->created_at?->diffInMinutes($r->decided_at))->sort()->values();
        $approved = $requests->where('status', BookingRequestStatus::Approved)->count();

        $drafts = Message::query()->whereNotNull('draft_outcome')->where('updated_at', '>=', $from)->where('updated_at', '<', $to)
            ->selectRaw('draft_outcome, count(*) as total')->groupBy('draft_outcome')->pluck('total', 'draft_outcome');

        $handovers = AuditLog::query()
            ->where('hotel_id', $this->context->id())
            ->where('event', 'conversations.handed_to_staff')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->get(['properties'])
            ->map(fn (AuditLog $log): string => (string) (explode(':', (string) ($log->properties['reason'] ?? 'other'))[0]))
            ->countBy()
            ->sortDesc()
            ->all();

        $ai = AiRun::query()->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $aiCost = (int) (clone $ai)->sum('cost_micro_usd');
        $blocked = (clone $ai)->where('outcome', 'blocked')->count();

        $whatsapp = UsageRecord::query()->where('meter', 'like', 'whatsapp.%')->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
            ->selectRaw('meter, sum(quantity) as total')->groupBy('meter')->pluck('total', 'meter');

        $median = $decisionMinutes->isEmpty() ? null : $decisionMinutes[intdiv($decisionMinutes->count(), 2)];

        return [
            'guest_messages' => $inbound,
            'conversations' => $conversations->count(),
            // Share of guest turns (one or more messages in a row) answered within a minute.
            'fast_reply_rate' => $turns === 0 ? null : (int) round(100 * $fast / $turns),
            'without_staff_rate' => $conversations->isEmpty() ? null : (int) round(100 * $withoutStaff / $conversations->count()),
            'requests' => $requests->count(),
            'median_decision_minutes' => $median,
            'request_to_booking_rate' => $requests->isEmpty() ? null : (int) round(100 * $approved / $requests->count()),
            'drafts' => [
                'sent_unchanged' => (int) ($drafts['sent_unchanged'] ?? 0),
                'edited' => (int) ($drafts['edited'] ?? 0),
                'discarded' => (int) ($drafts['discarded'] ?? 0),
            ],
            'handovers' => $handovers,
            'safety_blocks' => $blocked,
            'ai_cost_usd' => round($aiCost / 1_000_000, 2),
            'ai_cost_per_conversation_usd' => $conversations->isEmpty() ? null : round($aiCost / 1_000_000 / $conversations->count(), 3),
            'ai_cost_per_booking_usd' => $approved === 0 ? null : round($aiCost / 1_000_000 / $approved, 3),
            'whatsapp' => [
                'service' => (int) ($whatsapp['whatsapp.service'] ?? 0),
                'templates' => (int) collect($whatsapp)->filter(fn ($v, string $k): bool => str_starts_with($k, 'whatsapp.template.'))->sum(),
            ],
        ];
    }
}
