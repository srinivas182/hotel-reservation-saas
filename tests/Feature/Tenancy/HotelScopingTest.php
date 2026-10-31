<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Exceptions\HotelReassignmentForbidden;
use App\Domains\Tenancy\Exceptions\MissingHotelContext;
use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Note;

beforeEach(function (): void {
    $this->context = app(TenantContext::class);
    $this->hotelA = Hotel::factory()->create(['name' => 'Hotel A']);
    $this->hotelB = Hotel::factory()->create(['name' => 'Hotel B']);
});

function noteIn(Hotel $hotel, string $body = 'note'): Note
{
    return app(TenantContext::class)->run($hotel, fn (): Note => Note::create(['body' => $body]));
}

it('only returns records belonging to the current hotel', function (): void {
    noteIn($this->hotelA, 'a');
    noteIn($this->hotelB, 'b');

    $this->context->set($this->hotelA);

    expect(Note::query()->pluck('body')->all())->toBe(['a']);
});

it('returns 404 when a record of another hotel is requested by ID', function (): void {
    $user = User::factory()->create();
    app(HotelMembershipService::class)->add($this->hotelA, $user);
    $foreign = noteIn($this->hotelB, 'secret');
    $this->context->forget();

    Route::middleware(['web', 'auth', 'hotel'])
        ->get('/_fixtures/notes/{note}', fn (Note $note): string => $note->body);

    $this->actingAs($user)
        ->get("/_fixtures/notes/{$foreign->id}")
        ->assertNotFound();
});

it('serves a record of the current hotel by ID', function (): void {
    $user = User::factory()->create();
    app(HotelMembershipService::class)->add($this->hotelA, $user);
    $own = noteIn($this->hotelA, 'mine');
    $this->context->forget();

    Route::middleware(['web', 'auth', 'hotel'])
        ->get('/_fixtures/notes/{note}', fn (Note $note): string => $note->body);

    $this->actingAs($user)
        ->get("/_fixtures/notes/{$own->id}")
        ->assertOk()
        ->assertSee('mine');
});

it('fills hotel_id from the current hotel and ignores a supplied value', function (): void {
    $this->context->set($this->hotelA);

    $mass = Note::create(['body' => 'x', 'hotel_id' => $this->hotelB->id]);
    $forced = (new Note)->forceFill(['body' => 'y', 'hotel_id' => $this->hotelB->id]);
    $forced->save();

    expect($mass->hotel_id)->toBe($this->hotelA->id)
        ->and($forced->hotel_id)->toBe($this->hotelA->id);
});

it('refuses to move a record to another hotel', function (): void {
    $note = noteIn($this->hotelA);
    $this->context->set($this->hotelA);

    $note->forceFill(['hotel_id' => $this->hotelB->id])->save();
})->throws(HotelReassignmentForbidden::class);

it('fails closed when no hotel is set', function (): void {
    noteIn($this->hotelA);
    $this->context->forget();

    Note::query()->count();
})->throws(MissingHotelContext::class);

it('fails closed when creating without a hotel', function (): void {
    $this->context->forget();

    Note::create(['body' => 'orphan']);
})->throws(MissingHotelContext::class);

it('restores the previous hotel after run()', function (): void {
    $this->context->set($this->hotelA);

    $this->context->run($this->hotelB, fn () => expect($this->context->id())->toBe($this->hotelB->id));

    expect($this->context->id())->toBe($this->hotelA->id);
});

it('runs a callback once per hotel', function (): void {
    noteIn($this->hotelA, 'a1');
    noteIn($this->hotelB, 'b1');
    noteIn($this->hotelB, 'b2');

    $counts = [];
    $this->context->eachHotel(function (Hotel $hotel) use (&$counts): void {
        $counts[$hotel->name] = Note::query()->count();
    });

    expect($counts)->toBe(['Hotel A' => 1, 'Hotel B' => 2])
        ->and($this->context->has())->toBeFalse();
});

it('allows audited platform access across hotels', function (): void {
    noteIn($this->hotelA);
    noteIn($this->hotelB);
    $this->context->forget();

    $count = $this->context->bypass('Platform usage report', fn (): int => Note::query()->count());

    $entry = AuditLog::query()->where('event', 'tenancy.bypass')->sole();

    expect($count)->toBe(2)
        ->and($this->context->isBypassed())->toBeFalse()
        ->and($entry->properties->get('reason'))->toBe('Platform usage report');
});

it('requires a reason to bypass hotel scoping', function (): void {
    $this->context->bypass('  ', fn (): int => 1);
})->throws(InvalidArgumentException::class);
