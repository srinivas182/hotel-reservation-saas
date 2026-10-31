<?php

declare(strict_types=1);

use App\Domains\Tenancy\Exceptions\MissingHotelContext;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Note;
use Tests\Fixtures\RecordHotelJob;

beforeEach(function (): void {
    $this->context = app(TenantContext::class);
    $this->hotelA = Hotel::factory()->create();
    $this->hotelB = Hotel::factory()->create();
});

it('runs a queued job as the hotel it was dispatched for', function (): void {
    $this->context->run($this->hotelA, fn () => Note::create(['body' => 'a']));
    $this->context->run($this->hotelB, fn () => Note::create(['body' => 'b1']));
    $this->context->run($this->hotelB, fn () => Note::create(['body' => 'b2']));

    // A statement closure, so the PendingDispatch is sent before run() returns.
    $this->context->run($this->hotelB, function (): void {
        RecordHotelJob::dispatch()->onConnection('database');
    });
    $this->context->forget();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    expect(Cache::get('fixture.job_hotel'))->toBe($this->hotelB->id)
        ->and(Cache::get('fixture.job_note_count'))->toBe(2)
        ->and(app(TenantContext::class)->has())->toBeFalse();
});

it('rejects dispatching a hotel job without a current hotel', function (): void {
    $this->context->forget();

    RecordHotelJob::dispatch()->onConnection('database');
})->throws(MissingHotelContext::class);

it('keeps the request hotel after a synchronous job', function (): void {
    $this->context->set($this->hotelA);

    RecordHotelJob::dispatchSync();

    expect(Cache::get('fixture.job_hotel'))->toBe($this->hotelA->id)
        ->and($this->context->id())->toBe($this->hotelA->id);
});
