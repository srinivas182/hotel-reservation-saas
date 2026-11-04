<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\BookingRequests\Exceptions\RequestNotPossible;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

/**
 * Sends a booking request to reception and holds the rooms. It never
 * confirms a booking: reception decides.
 */
final class RequestBooking extends AssistantTool
{
    public function name(): string
    {
        return 'request_booking';
    }

    public function description(): string
    {
        return 'Send a booking request to reception and hold the rooms. Use only when the guest clearly wants to book and has given the room category, dates, number of guests and their full name. Reception confirms or declines; the booking is NOT confirmed by this tool.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'guest_name' => $schema->string()->description('The guest\'s full name as they gave it')->required(),
            'category_code' => $schema->string()->description('Room category code')->required(),
            'check_in' => $schema->string()->description('Arrival date, YYYY-MM-DD')->required(),
            'check_out' => $schema->string()->description('Departure date, YYYY-MM-DD')->required(),
            'adults' => $schema->integer()->required(),
            'children_ages' => $schema->array()->items($schema->integer()),
            'rooms' => $schema->integer(),
            'breakfast' => $schema->boolean(),
            'extra_bed' => $schema->boolean(),
        ];
    }

    protected function run(array $arguments): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $category = RoomCategory::query()->where('code', strtoupper((string) ($arguments['category_code'] ?? '')))->first();

        if ($category === null) {
            return ['error' => 'Unknown room category code.'];
        }

        try {
            $checkIn = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['check_in'] ?? ''));
            $checkOut = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['check_out'] ?? ''));
        } catch (Throwable) {
            return ['error' => 'Dates must be YYYY-MM-DD.'];
        }

        if ($checkIn === null || $checkOut === null) {
            return ['error' => 'Dates must be YYYY-MM-DD.'];
        }

        $stay = new StayRequest(
            categoryId: $category->id,
            checkIn: $checkIn,
            checkOut: $checkOut,
            adults: max(1, (int) ($arguments['adults'] ?? 1)),
            childAges: array_values(array_map(fn ($age): int => max(0, min(17, (int) $age)), (array) ($arguments['children_ages'] ?? []))),
            rooms: max(1, (int) ($arguments['rooms'] ?? 1)),
            breakfast: (bool) ($arguments['breakfast'] ?? false),
            extraBed: (bool) ($arguments['extra_bed'] ?? false),
        );

        if ($this->context->isPractice()) {
            return ['practice' => true, 'request_sent' => false, 'note' => 'Practice screen: no request was created. In a real conversation the request goes to reception and the rooms are held.'];
        }

        $conversation = Conversation::query()->with('contact.guest')->find($this->context->conversationId);

        if ($conversation === null) {
            return ['error' => 'Conversation not found.'];
        }

        try {
            $request = app(BookingRequestService::class)->submit($conversation, $stay, (string) ($arguments['guest_name'] ?? ''));
        } catch (RequestNotPossible $exception) {
            return ['request_sent' => false, 'reason' => $exception->reason, 'message' => $exception->getMessage()];
        }

        $total = Money::ofMinor($request->total_minor, $request->currency);
        $this->context->allowMoney($total);

        return [
            'request_sent' => true,
            'reference' => $request->reference,
            'total_to_pay' => MoneyFormatter::format($total, $this->context->locale !== '' ? $this->context->locale : $hotel->default_locale),
            'rooms_held_until' => $request->expires_at->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
            'next_step' => 'Tell the guest the request was sent and reception will confirm it. Do NOT say it is confirmed.',
        ];
    }
}
