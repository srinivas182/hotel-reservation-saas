<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests;

use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Rates\Quote\QuoteIssue;
use App\Domains\Rates\Quote\QuoteService;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;

/**
 * A pending request as reception sees it, with the price recalculated now.
 */
final class BookingRequestPresenter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly QuoteService $quotes,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(?string $conversationId = null): array
    {
        return array_values(BookingRequest::query()
            ->with(['guest', 'category'])
            ->where('status', BookingRequestStatus::Pending->value)
            ->when($conversationId !== null, fn ($q) => $q->where('conversation_id', $conversationId))
            ->orderBy('created_at')
            ->get()
            ->map(fn (BookingRequest $request): array => $this->present($request))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function present(BookingRequest $request): array
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $now = $this->quotes->quote($request->stay());
        $quoted = Money::ofMinor($request->total_minor, $request->currency);
        // The request's own held rooms are not free to itself: ignore "not available" here.
        $issues = array_values(array_filter($now->issues, fn (QuoteIssue $i): bool => $i->code !== 'not_available'));

        return [
            'id' => $request->id,
            'reference' => $request->reference,
            'conversation_id' => $request->conversation_id,
            'guest' => $request->guest?->name(),
            'phone' => $request->guest?->phone_e164,
            'category' => $request->category?->localizedName($locale, $locale),
            'room_category_id' => $request->room_category_id,
            'check_in' => $request->check_in->toDateString(),
            'check_out' => $request->check_out->toDateString(),
            'nights' => (int) $request->check_in->diffInDays($request->check_out),
            'rooms' => $request->rooms,
            'adults' => $request->adults,
            'children' => implode(', ', array_map('strval', array_filter($request->children_ages, fn ($a): bool => $a !== null))),
            'breakfast' => $request->breakfast,
            'extra_bed' => $request->extra_bed,
            'quoted_total' => MoneyFormatter::format($quoted, $locale),
            'current_total' => MoneyFormatter::format($now->total, $locale),
            'price_changed' => ! $now->total->isEqualTo($quoted),
            'problems' => array_map(fn (QuoteIssue $i): string => $i->message, $issues),
            'received_at' => $request->created_at?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
            'waiting_minutes' => $request->created_at === null ? 0 : (int) $request->created_at->diffInMinutes(now()),
            'expires_at' => $request->expires_at->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
            'minutes_left' => max(0, (int) now()->diffInMinutes($request->expires_at, false)),
        ];
    }
}
