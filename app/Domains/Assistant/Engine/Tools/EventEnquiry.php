<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Conversations\Models\Conversation;
use App\Domains\Events\EventService;
use App\Domains\Events\EventType;
use App\Domains\Events\Models\EventBlock;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

/**
 * Events (seminars, weddings, parties) are quoted by the events team.
 * The assistant collects the details and creates an enquiry; it never
 * gives an event price.
 */
final class EventEnquiry extends AssistantTool
{
    public function name(): string
    {
        return 'event_enquiry';
    }

    public function description(): string
    {
        return 'Create an event enquiry (seminar, meeting, wedding, party, conference) for the events team, once you have the date, type, number of people and the contact name. The team sends a quote: never give an event price.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_map(fn (EventType $t): string => $t->value, EventType::cases()))->required(),
            'date' => $schema->string()->description('YYYY-MM-DD')->required(),
            'people' => $schema->integer()->required(),
            'time' => $schema->string()->description('Morning, afternoon, evening, full day… as the guest said'),
            'needs' => $schema->string()->description('Meals, equipment, rooms for attendees, layout…'),
            'contact_name' => $schema->string()->required(),
            'company' => $schema->string()->description('Company or family, if given'),
        ];
    }

    protected function run(array $arguments): array
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['date'] ?? ''));
        } catch (Throwable) {
            $date = null;
        }

        $hotel = app(TenantContext::class)->hotel();

        if (! $date instanceof CarbonImmutable || $date->lt(HotelClock::today($hotel))) {
            return ['created' => false, 'error' => 'Ask for the event date (from today), as YYYY-MM-DD.'];
        }

        if (trim((string) ($arguments['contact_name'] ?? '')) === '') {
            return ['created' => false, 'error' => 'Ask for the contact name first.'];
        }

        if ($this->context->isPractice()) {
            return ['created' => false, 'practice' => true, 'note' => 'Practice screen: no enquiry was created.'];
        }

        $conversation = Conversation::query()->with('contact.guest')->find($this->context->conversationId);
        $type = EventType::tryFrom((string) ($arguments['type'] ?? '')) ?? EventType::Other;
        $time = trim((string) ($arguments['time'] ?? ''));
        $block = $time === '' ? null : EventBlock::query()->get()->first(fn (EventBlock $b): bool => collect($b->name)->contains(fn (string $n): bool => mb_stripos($time, $n) !== false || mb_stripos($n, $time) !== false));

        $event = app(EventService::class)->enquire([
            'client_name' => mb_substr(trim((string) $arguments['contact_name']), 0, 120),
            'company' => trim((string) ($arguments['company'] ?? '')) ?: null,
            'phone' => $conversation?->contact?->wa_id !== null ? '+'.$conversation->contact->wa_id : null,
            'guest_id' => $conversation?->contact?->guest?->id,
            'conversation_id' => $conversation?->id,
            'type' => $type,
            'source' => 'whatsapp',
            'date' => $date,
            'event_block_id' => $block?->id,
            'people' => max(1, min(10000, (int) ($arguments['people'] ?? 1))),
            'notes' => trim(($time !== '' ? "Time: {$time}\n" : '').(string) ($arguments['needs'] ?? '')) ?: null,
        ]);

        app(StaffAlerts::class)->notify(AlertKind::EventEnquiry, sprintf('%s · %s · %s · %d', $event->reference, $type->label(), $date->toDateString(), $event->people), $event->id);

        return ['created' => true, 'reference' => $event->reference, 'next_step' => 'Tell the guest the events team will prepare a quote and contact them. Do not give any event price or promise availability.'];
    }
}
