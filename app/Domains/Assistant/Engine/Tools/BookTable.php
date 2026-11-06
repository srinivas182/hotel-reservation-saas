<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Conversations\Models\Conversation;
use App\Domains\Restaurant\Exceptions\TableNotPossible;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\TableBookingStatus;
use App\Domains\Restaurant\TableRequests;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

/**
 * Books a table. Small ordinary parties are confirmed by the system (which
 * sends the confirmation itself); others become a request for staff.
 */
final class BookTable extends AssistantTool
{
    public function name(): string
    {
        return 'book_table';
    }

    public function description(): string
    {
        return 'Book a table once the guest has chosen the outlet, date, time and number of people and given their name. The hotel system sends the guest the confirmation itself; never write that the table is confirmed.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'outlet' => $schema->string()->description('Outlet code from restaurant_availability')->required(),
            'date' => $schema->string()->description('YYYY-MM-DD')->required(),
            'time' => $schema->string()->description('HH:MM, one of the free times')->required(),
            'people' => $schema->integer()->required(),
            'guest_name' => $schema->string()->description('The guest\'s name as they gave it')->required(),
            'notes' => $schema->string()->description('Occasion or requests the guest mentioned'),
        ];
    }

    protected function run(array $arguments): array
    {
        if ($this->context->isPractice()) {
            return ['practice' => true, 'booked' => false, 'note' => 'Practice screen: no table was booked.'];
        }

        $outlet = Outlet::query()->where('public_slug', (string) ($arguments['outlet'] ?? ''))->where('active', true)->first();
        $conversation = Conversation::query()->with('contact.guest')->find($this->context->conversationId);

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['date'] ?? ''));
        } catch (Throwable) {
            $date = null;
        }

        if ($outlet === null || $conversation === null || ! $date instanceof CarbonImmutable || preg_match('/^\d{2}:\d{2}$/', (string) ($arguments['time'] ?? '')) !== 1) {
            return ['booked' => false, 'error' => 'Check the outlet code, date (YYYY-MM-DD) and time (HH:MM).'];
        }

        try {
            $booking = app(TableRequests::class)->fromWhatsApp(
                $conversation,
                $outlet,
                $date,
                (string) $arguments['time'],
                max(1, (int) ($arguments['people'] ?? 1)),
                (string) ($arguments['guest_name'] ?? ''),
                isset($arguments['notes']) && trim((string) $arguments['notes']) !== '' ? (string) $arguments['notes'] : null,
            );
        } catch (TableNotPossible $e) {
            return ['booked' => false, 'reason' => $e->reason, 'message' => $e->getMessage()];
        }

        return $booking->status === TableBookingStatus::Confirmed
            ? ['booked' => true, 'reference' => $booking->reference, 'next_step' => 'Tell the guest the hotel is sending the confirmation now. Do NOT write that it is confirmed.']
            : ['booked' => false, 'request_sent' => true, 'reference' => $booking->reference, 'next_step' => 'Tell the guest the request was sent to the restaurant team, who will reply soon.'];
    }
}
