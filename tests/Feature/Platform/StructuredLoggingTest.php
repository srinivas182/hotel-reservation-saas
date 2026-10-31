<?php

declare(strict_types=1);

use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

it('adds request, user and hotel IDs to the log context', function (): void {
    $user = User::factory()->create();
    $hotel = Hotel::factory()->create();
    app(HotelMembershipService::class)->add($hotel, $user);

    $captured = [];
    Route::middleware(['web', 'auth'])->get('/_fixtures/context', function () use (&$captured): string {
        $captured = Context::all();

        return 'ok';
    });

    $this->actingAs($user)->get('/_fixtures/context')->assertOk();

    expect($captured)->toHaveKeys(['request_id', 'user_id', 'hotel_id'])
        ->and($captured['user_id'])->toBe($user->id)
        ->and($captured['hotel_id'])->toBe($hotel->id);
});
