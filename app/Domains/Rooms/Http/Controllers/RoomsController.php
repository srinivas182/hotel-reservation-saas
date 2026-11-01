<?php

declare(strict_types=1);

namespace App\Domains\Rooms\Http\Controllers;

use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rooms\Amenity;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Rooms\Models\RoomClosure;
use App\Domains\Rooms\RoomInventory;
use App\Domains\Rooms\RoomStatus;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Room categories, rooms and closures on one page.
 */
final class RoomsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request, RoomInventory $inventory): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $today = HotelClock::today($hotel);

        $categories = RoomCategory::query()->orderBy('sort_order')->orderBy('code')->get();
        $rooms = Room::query()->orderBy('number')->get();
        $closures = RoomClosure::query()
            ->where('to_date', '>=', $today->toDateString())
            ->orderBy('from_date')
            ->get()
            ->groupBy('room_id');

        $sellableTonight = $categories->isEmpty()
            ? []
            : $inventory->sellable(array_values(array_map('strval', $categories->pluck('id')->all())), $today, $today);

        return Inertia::render('hotel/rooms', [
            'locales' => HotelSettings::current()->enabled_locales ?? [$locale],
            'defaultLocale' => $locale,
            'categories' => $categories->map(fn (RoomCategory $category): array => [
                ...$category->only([
                    'id', 'code', 'name', 'description', 'standard_occupancy', 'max_adults',
                    'max_children', 'max_occupancy', 'extra_bed_allowed', 'active', 'sort_order',
                ]),
                'amenities' => $category->amenities ?? [],
                'display_name' => $category->localizedName($locale, $locale),
                'rooms_count' => $rooms->where('room_category_id', $category->id)->count(),
                'sellable_tonight' => $sellableTonight[$category->id][$today->toDateString()] ?? 0,
            ])->values(),
            'rooms' => $rooms->map(fn (Room $room): array => [
                ...$room->only(['id', 'room_category_id', 'number', 'floor', 'notes']),
                'status' => $room->status->value,
                'closures' => ($closures[$room->id] ?? collect())->map(fn (RoomClosure $closure): array => [
                    'id' => $closure->id,
                    'from_date' => $closure->from_date->toDateString(),
                    'to_date' => $closure->to_date->toDateString(),
                    'reason' => $closure->reason,
                ])->values(),
            ])->values(),
            'statuses' => collect(RoomStatus::cases())->map(fn (RoomStatus $s): array => ['value' => $s->value, 'label' => $s->label()])->values(),
            'amenities' => collect(Amenity::cases())->map(fn (Amenity $a): array => ['value' => $a->value, 'label' => $a->label()])->values(),
            'canManage' => $request->user()?->can('rooms.manage') === true,
        ]);
    }
}
