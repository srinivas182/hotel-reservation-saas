<?php

declare(strict_types=1);

namespace App\Domains\Events\Http\Controllers;

use App\Domains\Events\EventAvailability;
use App\Domains\Events\EventService;
use App\Domains\Events\EventStatus;
use App\Domains\Events\EventType;
use App\Domains\Events\Exceptions\EventNotPossible;
use App\Domains\Events\Layout;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\EventBlock;
use App\Domains\Events\Models\EventSpace;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Events calendar, enquiries and the life of an event.
 */
final class EventController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventService $events,
    ) {}

    public function index(Request $request): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = isset($data['month']) ? CarbonImmutable::parse($data['month'].'-01') : HotelClock::today($hotel)->startOfMonth();
        $spaces = EventSpace::query()->where('active', true)->orderBy('sort_order')->get();
        $blocks = EventBlock::query()->orderBy('sort_order')->orderBy('starts_at')->get();

        $inMonth = Event::query()->with(['space', 'block'])
            ->whereBetween('date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->whereIn('status', [EventStatus::Option->value, EventStatus::Confirmed->value, EventStatus::Done->value])
            ->orderBy('date')->get();

        return Inertia::render('hotel/events', [
            'month' => $month->format('Y-m'),
            'days' => $month->daysInMonth,
            'spaces' => $spaces->map(fn (EventSpace $s): array => ['id' => $s->id, 'name' => $s->localizedName($locale, $locale)])->values(),
            'calendar' => $inMonth->map(fn (Event $e): array => [
                'id' => $e->id, 'reference' => $e->reference, 'day' => (int) $e->date->format('j'), 'space' => $e->event_space_id,
                'block' => $e->block?->localizedName($locale, $locale), 'client' => $e->company ?: $e->client_name,
                'status' => $e->status->value, 'waitlisted' => $e->waitlisted, 'live' => $e->holdsSpace() || $e->status === EventStatus::Done,
            ])->values(),
            'open' => Event::query()->with(['space', 'block'])->whereIn('status', [EventStatus::Enquiry->value, EventStatus::Quoted->value, EventStatus::Option->value])
                ->orderBy('date')->limit(100)->get()->map(fn (Event $e): array => $this->summary($e))->values(),
            'blocks' => $blocks->map(fn (EventBlock $b): array => ['id' => $b->id, 'name' => $b->localizedName($locale, $locale)])->values(),
            'types' => collect(EventType::cases())->map(fn (EventType $t): array => ['value' => $t->value, 'label' => $t->label()])->values(),
            'layouts' => collect(Layout::cases())->map(fn (Layout $l): array => ['value' => $l->value, 'label' => $l->label()])->values(),
            'can' => ['manage' => $request->user()?->can('events.manage') === true],
        ]);
    }

    public function show(Request $request, Event $event, EventAvailability $availability): Response
    {
        $locale = $this->context->hotel()->default_locale;
        $event->load(['space', 'block', 'owner']);
        $clash = $event->space !== null && $event->block !== null && ! $event->holdsSpace() && $event->status->isOpen()
            ? $availability->clash($event->space, $event->block, $event->date, $event->id) : null;

        return Inertia::render('hotel/events/show', [
            'event' => [
                ...$this->summary($event),
                'phone' => $event->phone, 'email' => $event->email, 'notes' => $event->notes, 'owner' => $event->owner?->name,
                'event_space_id' => $event->event_space_id, 'event_block_id' => $event->event_block_id, 'layout' => $event->layout,
                'lost_reason' => $event->lost_reason, 'source' => $event->source,
            ],
            'clash' => $clash === null ? null : ['reference' => $clash->reference, 'status' => $clash->status->label(), 'id' => $clash->id],
            'spaces' => EventSpace::query()->where('active', true)->orderBy('sort_order')->get()->map(fn (EventSpace $s): array => ['id' => $s->id, 'name' => $s->localizedName($locale, $locale), 'capacities' => (object) $s->capacities])->values(),
            'blocks' => EventBlock::query()->orderBy('sort_order')->get()->map(fn (EventBlock $b): array => ['id' => $b->id, 'name' => $b->localizedName($locale, $locale)])->values(),
            'types' => collect(EventType::cases())->map(fn (EventType $t): array => ['value' => $t->value, 'label' => $t->label()])->values(),
            'layouts' => collect(Layout::cases())->map(fn (Layout $l): array => ['value' => $l->value, 'label' => $l->label()])->values(),
            'can' => ['manage' => $request->user()?->can('events.manage') === true],
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $event = $this->events->enquire([...$this->validated($request), 'source' => (string) $request->input('source', 'phone')], $user);

        return to_route('hotel.events.show', $event);
    }

    public function update(Request $request, Event $event, #[CurrentUser] User $user): RedirectResponse
    {
        if (! $event->status->isOpen()) {
            throw ValidationException::withMessages(['status' => __('This event is closed.')]);
        }

        $before = [$event->event_space_id, $event->event_block_id, $event->date->toDateString()];
        $event->update($this->validated($request));

        // Changing the space, time or date of a held option re-checks it.
        if ($event->status === EventStatus::Option && $before !== [$event->event_space_id, $event->event_block_id, $event->date->toDateString()]) {
            $this->attempt(fn () => $this->events->option($event, $event->option_until, $user));
        }

        return back();
    }

    public function option(Request $request, Event $event, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today']]);
        $until = isset($data['until']) ? CarbonImmutable::parse($data['until'].' 23:59', $this->context->hotel()->timezone)->utc() : null;

        return $this->attempt(fn () => $this->events->option($event, $until, $user));
    }

    public function confirm(Event $event, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->attempt(fn () => $this->events->confirm($event, $user));
    }

    public function close(Request $request, Event $event, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['done', 'lost', 'cancelled'])], 'reason' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(fn () => $this->events->close($event, EventStatus::from((string) $data['status']), $user, $data['reason'] ?? null));
    }

    private function attempt(callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (EventNotPossible $e) {
            throw ValidationException::withMessages(['event' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $id = $this->context->id();

        return $request->validate([
            'client_name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:25'],
            'email' => ['nullable', 'email', 'max:191'],
            'type' => ['required', Rule::enum(EventType::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'event_block_id' => ['nullable', 'integer', Rule::exists('event_blocks', 'id')->where('hotel_id', $id)],
            'event_space_id' => ['nullable', 'string', Rule::exists('event_spaces', 'id')->where('hotel_id', $id)],
            'layout' => ['nullable', Rule::enum(Layout::class)],
            'people' => ['required', 'integer', 'min:1', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Event $e): array
    {
        $locale = $this->context->hotel()->default_locale;

        return [
            'id' => $e->id,
            'reference' => $e->reference,
            'client_name' => $e->client_name,
            'company' => $e->company,
            'type' => $e->type->value,
            'type_label' => $e->type->label(),
            'date' => $e->date->toDateString(),
            'space' => $e->space?->localizedName($locale, $locale),
            'block' => $e->block?->localizedName($locale, $locale),
            'people' => $e->people,
            'status' => $e->status->value,
            'status_label' => $e->status->label(),
            'option_until' => $e->option_until?->setTimezone($this->context->hotel()->timezone)->format('Y-m-d'),
            'waitlisted' => $e->waitlisted,
            'holds' => $e->holdsSpace(),
        ];
    }
}
