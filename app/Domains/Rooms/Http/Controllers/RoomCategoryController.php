<?php

declare(strict_types=1);

namespace App\Domains\Rooms\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rooms\Amenity;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class RoomCategoryController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request): RedirectResponse
    {
        $category = RoomCategory::query()->create($this->validated($request, null));

        Audit::record('rooms.category_created', 'Room category created', subject: $this->context->hotel(), properties: $this->auditData($category));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room category created.')]);

        return back();
    }

    public function update(Request $request, RoomCategory $roomCategory): RedirectResponse
    {
        $before = $this->auditData($roomCategory);
        $roomCategory->update($this->validated($request, $roomCategory));

        Audit::record('rooms.category_updated', 'Room category updated', subject: $this->context->hotel(), properties: [
            'old' => $before,
            'new' => $this->auditData($roomCategory),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room category updated.')]);

        return back();
    }

    public function destroy(RoomCategory $roomCategory): RedirectResponse
    {
        if ($roomCategory->rooms()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This category still has rooms. Move or remove them, or switch the category off instead.')]);

            return back();
        }

        $data = $this->auditData($roomCategory);
        $roomCategory->delete();

        Audit::record('rooms.category_deleted', 'Room category deleted', subject: $this->context->hotel(), properties: $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room category deleted.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?RoomCategory $category): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $hotel = $this->context->hotel();
        $enabled = HotelSettings::current()->enabled_locales ?? [$hotel->default_locale];

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('room_categories', 'code')->where('hotel_id', $hotel->getKey())->ignore($category?->getKey()),
            ],
            'name' => ['required', 'array'],
            "name.{$hotel->default_locale}" => ['required', 'string', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'array'],
            'description.*' => ['nullable', 'string', 'max:1000'],
            'max_occupancy' => ['required', 'integer', 'min:1', 'max:20'],
            'standard_occupancy' => ['required', 'integer', 'min:1', 'lte:max_occupancy'],
            'max_adults' => ['required', 'integer', 'min:1', 'lte:max_occupancy'],
            'max_children' => ['required', 'integer', 'min:0', 'lte:max_occupancy'],
            'extra_bed_allowed' => ['required', 'boolean'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => [Rule::in(Amenity::values())],
            'active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $translated = fn (?array $values): array => array_filter(
            Arr::only($values ?? [], $enabled),
            fn ($value): bool => is_string($value) && trim($value) !== '',
        );

        return [
            'code' => $data['code'],
            'name' => $translated($data['name']),
            'description' => $translated($data['description'] ?? []) ?: null,
            'standard_occupancy' => (int) $data['standard_occupancy'],
            'max_adults' => (int) $data['max_adults'],
            'max_children' => (int) $data['max_children'],
            'max_occupancy' => (int) $data['max_occupancy'],
            'extra_bed_allowed' => (bool) $data['extra_bed_allowed'],
            'amenities' => array_values(array_unique($data['amenities'] ?? [])),
            'active' => (bool) $data['active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditData(RoomCategory $category): array
    {
        return Arr::only($category->attributesToArray(), [
            'id', 'code', 'name', 'standard_occupancy', 'max_adults', 'max_children', 'max_occupancy', 'extra_bed_allowed', 'active',
        ]);
    }
}
