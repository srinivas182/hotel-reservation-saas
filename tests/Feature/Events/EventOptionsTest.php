<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Events\EventService;
use App\Domains\Events\EventStatus;
use App\Domains\Events\Exceptions\EventNotPossible;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\EventPackage;
use App\Domains\Events\Models\EventSpace;
use App\Domains\Events\Models\EventSpacePrice;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-13 10:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $this->s = eventSetup($this->hotel);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->user = staffMember($this->hotel, SystemRole::EventsCoordinator);
    $this->option = fn (Event $e) => ($this->in)(fn () => app(EventService::class)->option($e, null, $this->user)->fresh());
});

it('holds a space by time block, with combined spaces and full days', function (string $space, string $block, bool $waitlisted): void {
    ($this->option)(eventFor($this->hotel, $this->s['a'], $this->s['morning']));

    $second = ($this->option)(eventFor($this->hotel, $this->s[$space], $this->s[$block], client: 'Autre'));

    expect($second->waitlisted)->toBe($waitlisted);
})->with([
    'same space, afternoon' => ['a', 'afternoon', false],
    'same space, evening' => ['a', 'evening', false],
    'same space, full day' => ['a', 'day', true],
    'combined space, morning' => ['grande', 'morning', true],
    'other space, morning' => ['b', 'morning', false],
]);

it('puts the second option on the waiting list and moves it up when the first is lost', function (): void {
    $first = ($this->option)(eventFor($this->hotel, $this->s['a'], $this->s['day']));
    $second = ($this->option)(eventFor($this->hotel, $this->s['a'], $this->s['morning'], client: 'Mariage Touré'));

    expect($first->holdsSpace())->toBeTrue()->and($second->waitlisted)->toBeTrue()->and($second->holdsSpace())->toBeFalse();

    ($this->in)(fn () => app(EventService::class)->close($first, EventStatus::Lost, $this->user, 'Trop cher'));

    $second = ($this->in)(fn () => $second->fresh());
    expect($second->waitlisted)->toBeFalse()
        ->and($second->holdsSpace())->toBeTrue()
        ->and($second->option_until?->toDateString())->toBe('2026-11-20')
        ->and(AuditLog::query()->where('event', 'events.promoted')->exists())->toBeTrue();
});

it('releases options past their date and moves up the waiting list', function (): void {
    $first = ($this->option)(eventFor($this->hotel, $this->s['a'], $this->s['morning']));
    $second = ($this->option)(eventFor($this->hotel, $this->s['a'], $this->s['morning'], client: 'B'));

    $this->travel(8)->days();
    $this->artisan('events:expire-options')->assertSuccessful();

    expect(($this->in)(fn () => $first->fresh()->status))->toBe(EventStatus::Quoted)
        ->and(($this->in)(fn () => $second->fresh()->holdsSpace()))->toBeTrue();
});

it('confirms only when the space is free, the room is big enough and the date is ahead', function (): void {
    $grande = eventFor($this->hotel, $this->s['grande'], $this->s['evening']);
    acceptQuoteAndPayDeposit($this->hotel, $grande, $this->user);
    ($this->in)(fn () => app(EventService::class)->confirm($grande, $this->user));

    $b = eventFor($this->hotel, $this->s['b'], $this->s['evening']);
    acceptQuoteAndPayDeposit($this->hotel, $b, $this->user);
    expect(fn () => ($this->in)(fn () => app(EventService::class)->confirm($b, $this->user)))
        ->toThrow(fn (EventNotPossible $e) => expect($e->reason)->toBe('taken'))
        ->and(fn () => ($this->option)(eventFor($this->hotel, $this->s['a'], $this->s['morning'], people: 50, layout: 'banquet')))
        ->toThrow(fn (EventNotPossible $e) => expect($e->reason)->toBe('capacity'))
        ->and(fn () => ($this->option)(eventFor($this->hotel, $this->s['a'], $this->s['morning'], date: '2026-11-01')))
        ->toThrow(fn (EventNotPossible $e) => expect($e->reason)->toBe('past'));
});

it('takes enquiries and runs the event from the screens', function (): void {
    asStaffOf($this->hotel, $this->user)->post(route('hotel.events.store'), [
        'client_name' => 'Awa Koné', 'company' => 'Banque Atlantique', 'phone' => '+2250700000001', 'type' => 'seminar',
        'date' => '2026-12-12', 'event_block_id' => $this->s['day']->id, 'event_space_id' => $this->s['a']->id, 'layout' => 'theatre', 'people' => 40, 'source' => 'email',
    ])->assertRedirect();
    $event = ($this->in)(fn () => Event::query()->sole());
    expect($event->reference)->toBe('E-26-0001')->and($event->status)->toBe(EventStatus::Enquiry)->and($event->source)->toBe('email');

    asStaffOf($this->hotel, $this->user)->post(route('hotel.events.option', $event), ['until' => '2026-11-25'])->assertSessionHasNoErrors();
    expect(($this->in)(fn () => $event->fresh()->option_until?->toDateString()))->toBe('2026-11-25');

    asStaffOf($this->hotel, $this->user)->get(route('hotel.events', ['month' => '2026-12']))->assertInertia(fn ($page) => $page->has('calendar', 1)->where('calendar.0.day', 12));
    acceptQuoteAndPayDeposit($this->hotel, $event, $this->user);
    asStaffOf($this->hotel, $this->user)->post(route('hotel.events.confirm', $event))->assertSessionHasNoErrors();
    expect(($this->in)(fn () => $event->fresh()->status))->toBe(EventStatus::Confirmed);

    $reception = staffMember($this->hotel, SystemRole::Reception);
    asStaffOf($this->hotel, $reception)->get(route('hotel.events.show', $event))->assertOk();
    asStaffOf($this->hotel, $reception)->post(route('hotel.events.close', $event), ['status' => 'cancelled', 'reason' => 'x'])->assertForbidden();

    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->get(route('hotel.events.show', $event))->assertNotFound();
});

it('sets up spaces, blocks, prices and packages', function (): void {
    asStaffOf($this->hotel, $this->user)->post(route('hotel.events.spaces.store'), [
        'name' => ['fr' => 'Jardin'], 'capacities' => ['cocktail' => 200, 'banquet' => 150], 'combines' => [], 'active' => true,
    ])->assertSessionHasNoErrors();
    $garden = ($this->in)(fn () => EventSpace::query()->whereJsonContains('name->fr', 'Jardin')->first() ?? EventSpace::query()->latest('created_at')->first());

    asStaffOf($this->hotel, $this->user)->put(route('hotel.events.prices'), ['prices' => [
        ['space' => $this->s['a']->id, 'block' => $this->s['day']->id, 'price' => 150000],
        ['space' => $this->s['a']->id, 'block' => $this->s['morning']->id, 'price' => null],
    ]])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->user)->post(route('hotel.events.packages.store'), [
        'kind' => 'package', 'name' => ['fr' => 'Forfait journée'], 'price' => 15000, 'unit' => 'per_person', 'active' => true,
    ])->assertSessionHasNoErrors();

    expect($garden?->capacities)->toEqual(['cocktail' => 200, 'banquet' => 150])
        ->and(($this->in)(fn () => EventSpacePrice::query()->sole()->price_minor))->toBe(150000)
        ->and(($this->in)(fn () => EventPackage::query()->sole()->unit))->toBe('per_person');

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->get(route('hotel.events.setup'))->assertForbidden();
});
