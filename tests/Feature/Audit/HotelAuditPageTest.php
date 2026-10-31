<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Audit;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/../Access/helpers.php';

it('shows only the current hotel\'s audit entries to people allowed to see them', function (): void {
    $hotel = Hotel::factory()->create();
    $other = Hotel::factory()->create();
    $readOnly = staffMember($hotel, SystemRole::ReadOnly);

    app(TenantContext::class)->run($hotel, fn () => Audit::record('test.ours', 'Ours'));
    app(TenantContext::class)->run($other, fn () => Audit::record('test.theirs', 'Theirs'));

    asStaffOf($hotel, $readOnly)
        ->get(route('hotel.audit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entries.data', fn ($entries): bool => collect($entries)->pluck('event')->contains('test.ours')
                && ! collect($entries)->pluck('event')->contains('test.theirs')));

    asStaffOf($hotel, staffMember($hotel, SystemRole::Reception))->get(route('hotel.audit'))->assertForbidden();
});

it('filters by event', function (): void {
    $hotel = Hotel::factory()->create();
    $manager = staffMember($hotel, SystemRole::Manager);

    app(TenantContext::class)->run($hotel, function (): void {
        Audit::record('test.a', 'A');
        Audit::record('test.b', 'B');
    });

    asStaffOf($hotel, $manager)
        ->get(route('hotel.audit', ['event' => 'test.b']))
        ->assertInertia(fn ($page) => $page->where('entries.data', fn ($entries): bool => collect($entries)->pluck('event')->unique()->values()->all() === ['test.b']));
});
