<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Tenancy\Models\Hotel;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

it('never suggests the same room to two arrivals on the Today page', function (): void {
    $this->travelTo('2026-11-01 09:00:00');
    $hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $category = roomCategory($hotel, 'STD', 3);
    rateFor($hotel, $category);
    foreach (range(1, 3) as $i) {
        book($hotel, $category, '2026-11-01', '2026-11-02', phone: "+225070000000{$i}");
    }

    asStaffOf($hotel, staffMember($hotel, SystemRole::Reception))->get(route('hotel.today'))
        ->assertInertia(fn ($page) => $page->has('arrivals', 3)->where('arrivals', function ($arrivals): bool {
            $rooms = collect($arrivals)->flatMap(fn ($a) => array_values((array) $a['suggested_rooms']))->all();

            return count($rooms) === 3 && count(array_unique($rooms)) === 3;
        }));
});
