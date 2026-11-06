<?php

declare(strict_types=1);

namespace App\Domains\Events;

use App\Domains\Audit\Audit;
use App\Domains\Events\Exceptions\EventNotPossible;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\EventBlock;
use App\Domains\Events\Models\EventSpace;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Enquiries, options (spaces held until a date, with a waiting list),
 * confirmation and closing. Space changes lock the hotel's spaces so two
 * events can never hold the same space at the same time.
 */
final class EventService
{
    public const OPTION_DAYS = 7;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventAvailability $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function enquire(array $data, ?User $by = null): Event
    {
        $event = Event::query()->create([
            ...$data,
            'reference' => $this->nextReference(),
            'status' => EventStatus::Enquiry,
            'owner_user_id' => $data['owner_user_id'] ?? $by?->getKey(),
        ]);

        Audit::record('events.enquiry', 'Event enquiry received', subject: $event, causer: $by, properties: ['reference' => $event->reference, 'source' => $event->source]);

        return $event;
    }

    /**
     * Hold the space until a date, or join the waiting list when it is held.
     */
    public function option(Event $event, ?CarbonImmutable $until, User $by): Event
    {
        if (! in_array($event->status, [EventStatus::Enquiry, EventStatus::Quoted, EventStatus::Option], true)) {
            throw EventNotPossible::because('status');
        }

        $until ??= now()->addDays(self::OPTION_DAYS)->toImmutable();

        DB::transaction(function () use ($event, $until): void {
            [$space, $block] = $this->lockAndLoad($event);
            $clash = $this->availability->clash($space, $block, $event->date, $event->id);

            $event->forceFill($clash === null
                ? ['status' => EventStatus::Option, 'option_until' => $until, 'waitlisted' => false, 'waitlisted_at' => null]
                : ['status' => EventStatus::Option, 'option_until' => $until, 'waitlisted' => true, 'waitlisted_at' => $event->waitlisted_at ?? now()])->save();
        });

        Audit::record($event->waitlisted ? 'events.waitlisted' : 'events.option', $event->waitlisted ? 'Event put on the waiting list' : 'Space held on option', subject: $event, causer: $by, properties: ['until' => $until->toIso8601String()]);

        return $event;
    }

    public function confirm(Event $event, User $by): Event
    {
        if (! in_array($event->status, [EventStatus::Enquiry, EventStatus::Quoted, EventStatus::Option], true)) {
            throw EventNotPossible::because('status');
        }

        DB::transaction(function () use ($event): void {
            [$space, $block] = $this->lockAndLoad($event);

            if ($this->availability->clash($space, $block, $event->date, $event->id) !== null) {
                throw EventNotPossible::because('taken');
            }

            $event->forceFill(['status' => EventStatus::Confirmed, 'waitlisted' => false, 'waitlisted_at' => null, 'option_until' => null])->save();
        });

        Audit::record('events.confirmed', 'Event confirmed', subject: $event, causer: $by);

        return $event;
    }

    public function close(Event $event, EventStatus $to, User $by, ?string $reason = null): Event
    {
        $allowed = match ($to) {
            EventStatus::Done => $event->status === EventStatus::Confirmed,
            EventStatus::Lost => in_array($event->status, [EventStatus::Enquiry, EventStatus::Quoted, EventStatus::Option], true),
            EventStatus::Cancelled => $event->status === EventStatus::Confirmed,
            default => false,
        };

        if (! $allowed) {
            throw EventNotPossible::because('status');
        }

        $held = $event->holdsSpace();
        $event->forceFill(['status' => $to, 'lost_reason' => $reason, 'waitlisted' => false])->save();
        Audit::record('events.'.$to->value, 'Event: '.$to->label(), subject: $event, causer: $by, properties: ['reason' => $reason]);

        if ($held && $to !== EventStatus::Done) {
            $this->promoteWaitlist($event);
        }

        return $event;
    }

    /**
     * Options past their date go back to "quoted"; the space goes to the
     * first event waiting for it, and the team is told.
     */
    public function expireOptions(): int
    {
        $count = 0;

        foreach (Event::query()->where('status', EventStatus::Option->value)->where('waitlisted', false)->where('option_until', '<=', now())->get() as $event) {
            $event->forceFill(['status' => EventStatus::Quoted, 'option_until' => null])->save();
            Audit::record('events.option_expired', 'Option expired', subject: $event);
            app(StaffAlerts::class)->notify(AlertKind::EventOptionExpired, $event->reference.' · '.$event->client_name.' · '.$event->date->toDateString(), $event->id);
            $this->promoteWaitlist($event);
            $count++;
        }

        return $count;
    }

    /**
     * Give a freed space to the first event waiting for it.
     */
    public function promoteWaitlist(Event $freed): ?Event
    {
        $waiting = Event::query()->where('date', $freed->date->toDateString())->where('waitlisted', true)
            ->where('status', EventStatus::Option->value)->orderBy('waitlisted_at')->get();

        foreach ($waiting as $candidate) {
            $promoted = DB::transaction(function () use ($candidate): bool {
                [$space, $block] = $this->lockAndLoad($candidate);

                if ($this->availability->clash($space, $block, $candidate->date, $candidate->id) !== null) {
                    return false;
                }

                $candidate->forceFill(['waitlisted' => false, 'waitlisted_at' => null, 'option_until' => now()->addDays(self::OPTION_DAYS)])->save();

                return true;
            });

            if ($promoted) {
                Audit::record('events.promoted', 'Event moved from the waiting list to an option', subject: $candidate);
                app(StaffAlerts::class)->notify(AlertKind::EventOptionExpired, $candidate->reference.' · '.__('now holds the space'), $candidate->id);

                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{0: EventSpace, 1: EventBlock}
     */
    private function lockAndLoad(Event $event): array
    {
        if ($event->event_space_id === null || $event->event_block_id === null) {
            throw EventNotPossible::because('no_space');
        }

        if ($event->date->lt(HotelClock::today($this->context->hotel()))) {
            throw EventNotPossible::because('past');
        }

        // Lock every space of the hotel in a fixed order: holds are serialised.
        $spaces = EventSpace::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $space = $spaces->get($event->event_space_id) ?? throw EventNotPossible::because('no_space');
        $block = EventBlock::query()->findOrFail($event->event_block_id);

        if ($event->layout !== null && $space->capacity($event->layout) < $event->people) {
            throw EventNotPossible::because('capacity');
        }

        return [$space, $block];
    }

    private function nextReference(): string
    {
        $hotel = $this->context->hotel();
        $year = (int) now($hotel->timezone)->format('Y');

        DB::table('event_sequences')->insertOrIgnore(['hotel_id' => $hotel->getKey(), 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('event_sequences')->where('hotel_id', $hotel->getKey())->where('year', $year)->lockForUpdate()->first(['id', 'last_number']);
        $number = (int) ($row->last_number ?? 0) + 1;
        DB::table('event_sequences')->where('id', $row->id ?? 0)->update(['last_number' => $number, 'updated_at' => now()]);

        return sprintf('E-%s-%04d', substr((string) $year, -2), $number);
    }
}
