<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Events\EventService;
use App\Domains\Events\EventStatus;
use App\Domains\Events\Exceptions\EventNotPossible;
use App\Domains\Events\Models\EventPackage;
use App\Domains\Events\Models\EventQuote;
use App\Domains\Events\Models\EventSheet;
use App\Domains\Events\Models\EventSpacePrice;
use App\Domains\Events\Quotes;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Folio\Folio;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-13 10:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $this->s = eventSetup($this->hotel);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->coordinator = staffMember($this->hotel, SystemRole::EventsCoordinator);
    $this->manager = staffMember($this->hotel, SystemRole::Manager);
    $this->event = eventFor($this->hotel, $this->s['a'], $this->s['day'], people: 40);
    [$this->package, $this->projector] = ($this->in)(function (): array {
        EventSpacePrice::query()->create(['event_space_id' => $this->s['a']->id, 'event_block_id' => $this->s['day']->id, 'price_minor' => 150000]);
        TaxRate::query()->create(['name' => ['fr' => 'TVA'], 'type' => 'percentage', 'percentage_bps' => 1800, 'inclusive' => false, 'applies_to' => ['events'], 'active' => true]);

        return [
            EventPackage::query()->create(['kind' => 'package', 'name' => ['fr' => 'Forfait journée'], 'price_minor' => 15000, 'unit' => 'per_person']),
            EventPackage::query()->create(['kind' => 'extra', 'name' => ['fr' => 'Vidéoprojecteur'], 'price_minor' => 25000, 'unit' => 'per_event']),
        ];
    });
    $this->payload = fn (array $extra = []) => [
        'lines' => [
            ['kind' => 'space', 'quantity' => 1],
            ['kind' => 'package', 'ref' => $this->package->id, 'quantity' => 40],
            ['kind' => 'extra', 'ref' => $this->projector->id, 'quantity' => 1],
            ['kind' => 'free', 'description' => 'Décoration florale', 'unit_price' => 10000, 'quantity' => 2],
        ],
        ...$extra,
    ];
});

it('builds a quote from the space, packages, extras, free lines, a discount and the Events tax', function (): void {
    asStaffOf($this->hotel, $this->manager)->post(route('hotel.events.quotes.store', $this->event), ($this->payload)(['discount' => 50000, 'discount_reason' => 'Client fidèle']))->assertSessionHasNoErrors();

    $quote = ($this->in)(fn () => EventQuote::query()->with('lines')->sole());
    // 150 000 + 40 × 15 000 + 25 000 + 2 × 10 000 = 795 000; − 50 000 = 745 000; + 18 % = 879 100
    expect($quote->subtotal_minor)->toBe(795000)
        ->and($quote->discount_minor)->toBe(50000)
        ->and($quote->tax_minor)->toBe(134100)
        ->and($quote->total_minor)->toBe(879100)
        ->and($quote->lines->pluck('description')->all())->toBe(['Salle A · Journée', 'Forfait journée', 'Vidéoprojecteur', 'Décoration florale'])
        ->and($quote->valid_until->toDateString())->toBe('2026-11-27');
});

it('needs the discount permission and a reason', function (): void {
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.quotes.store', $this->event), ($this->payload)(['discount' => 10000, 'discount_reason' => 'x']))->assertForbidden();
    asStaffOf($this->hotel, $this->manager)->post(route('hotel.events.quotes.store', $this->event), ($this->payload)(['discount' => 10000]))->assertSessionHasErrors('discount_reason');
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.quotes.store', $this->event), ($this->payload)())->assertSessionHasNoErrors();
});

it('keeps earlier versions, and only the latest sent link can be accepted', function (): void {
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.quotes.store', $this->event), ($this->payload)());
    $v1 = ($this->in)(fn () => EventQuote::query()->sole());
    $link1 = null;
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.event-quotes.send', $v1))->assertSessionHas('inertia.flash_data', function (array $f) use (&$link1): bool {
        $link1 = $f['quote_link'];

        return str_contains($link1, '/q/');
    });
    expect(($this->in)(fn () => $this->event->fresh()->status))->toBe(EventStatus::Quoted);

    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.quotes.store', $this->event), ($this->payload)());
    $v2 = ($this->in)(fn () => EventQuote::query()->where('version', 2)->sole());
    $link2 = null;
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.event-quotes.send', $v2))->assertSessionHas('inertia.flash_data', function (array $f) use (&$link2): bool {
        $link2 = $f['quote_link'];

        return true;
    });

    $this->flushSession();
    expect(($this->in)(fn () => $v1->fresh()->status))->toBe('superseded');
    $this->get(parse_url((string) $link1, PHP_URL_PATH))->assertNotFound();
    $this->get(parse_url((string) $link2, PHP_URL_PATH))->assertOk()->assertSee('938')->assertSee('Vidéoprojecteur'); // 795 000 + 18 %

    $this->post(parse_url((string) $link2, PHP_URL_PATH), ['name' => 'Awa Koné', 'title' => 'DRH'])->assertSessionHasErrors('terms');
    $this->post(parse_url((string) $link2, PHP_URL_PATH), ['name' => 'Awa Koné', 'title' => 'DRH', 'terms' => '1'])->assertRedirect();

    $v2 = ($this->in)(fn () => $v2->fresh());
    expect($v2->status)->toBe('accepted')->and($v2->accepted_name)->toBe('Awa Koné')->and($v2->accepted_via)->toBe('online')->and($v2->accepted_ip)->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'events.quote_accepted')->exists())->toBeTrue();

    $this->post(parse_url((string) $link2, PHP_URL_PATH), ['name' => 'Autre', 'terms' => '1'])->assertStatus(410);
    $this->get('/q/'.str_repeat('x', 40))->assertNotFound();
    $this->get('/q/short')->assertNotFound();
});

it('refuses acceptance after the quote expires', function (): void {
    $quote = ($this->in)(function () {
        $q = app(Quotes::class)->create($this->event, [['kind' => 'free', 'description' => 'X', 'quantity' => 1, 'unit_price' => 1000]], 0, null, null, null, $this->coordinator);

        return [$q, app(Quotes::class)->send($q, $this->coordinator)];
    });
    $this->travel(20)->days();

    $this->get('/q/'.$quote[1])->assertOk()->assertSee('ne peut plus être accepté', false);
    $this->post('/q/'.$quote[1], ['name' => 'Awa', 'terms' => '1'])->assertStatus(410);
});

it('confirms only after an accepted quote and the deposit, then puts the quote on the event account', function (): void {
    $service = fn () => app(EventService::class)->confirm($this->event->fresh(), $this->coordinator);
    expect(fn () => ($this->in)($service))->toThrow(fn (EventNotPossible $e) => expect($e->reason)->toBe('no_quote'));

    $quote = ($this->in)(function () {
        $q = app(Quotes::class)->create($this->event, [['kind' => 'free', 'description' => 'Forfait', 'quantity' => 1, 'unit_price' => 100000]], 0, null, null, null, $this->coordinator);
        app(Quotes::class)->send($q, $this->coordinator);

        return app(Quotes::class)->accept($q->fresh(), 'Awa', null, null, 'staff', $this->coordinator);
    });
    expect(fn () => ($this->in)($service))->toThrow(fn (EventNotPossible $e) => expect($e->reason)->toBe('deposit'));

    $reception = staffMember($this->hotel, SystemRole::Reception);
    asStaffOf($this->hotel, $reception)->post(route('hotel.events.payments.store', $this->event), ['amount' => 35400, 'method' => 'cash'])->assertSessionHasNoErrors();
    ($this->in)($service);

    $statement = ($this->in)(fn () => app(Folio::class)->statement($this->event->fresh()));
    expect(($this->in)(fn () => $this->event->fresh()->status))->toBe(EventStatus::Confirmed)
        ->and($quote->total_minor)->toBe(118000)
        ->and($statement['charges_minor'])->toBe(118000)
        ->and($statement['balance_minor'])->toBe(118000 - 35400);

    asStaffOf($this->hotel, $reception)->get(route('hotel.events.statement', $this->event))->assertOk()->assertSee($this->event->reference);
});

it('versions the event sheet and flags changes after confirmation', function (): void {
    $sheet = ['timings' => [['time' => '10:30', 'what' => 'Pause café'], ['time' => '08:30', 'what' => 'Accueil']], 'menu' => 'Buffet ivoirien', 'setup' => 'Théâtre, 40 chaises'];

    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.sheet.save', $this->event), $sheet)->assertSessionHasNoErrors();
    acceptQuoteAndPayDeposit($this->hotel, $this->event, $this->coordinator);
    ($this->in)(fn () => app(EventService::class)->confirm($this->event->fresh(), $this->coordinator));
    asStaffOf($this->hotel, $this->coordinator)->post(route('hotel.events.sheet.save', $this->event), [...$sheet, 'menu' => 'Buffet ivoirien, sans porc'])->assertSessionHasNoErrors();

    $sheets = ($this->in)(fn () => EventSheet::query()->orderBy('version')->get());
    expect($sheets->pluck('after_confirmation')->all())->toBe([false, true])
        ->and($sheets[0]->content['timings'][0]['what'])->toBe('Accueil');

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->get(route('hotel.events.sheet', $this->event))
        ->assertOk()->assertSee('sans porc')->assertSee('Version 2');
});

it('keeps room bills working alongside event accounts', function (): void {
    expect(Illuminate\Support\Facades\Schema::hasColumn('folio_lines', 'event_id'))->toBeTrue();
    acceptQuoteAndPayDeposit($this->hotel, $this->event, $this->coordinator);

    expect(($this->in)(fn () => App\Domains\Folio\FolioLine::query()->whereNotNull('event_id')->whereNull('booking_id')->count()))->toBe(1);
});
