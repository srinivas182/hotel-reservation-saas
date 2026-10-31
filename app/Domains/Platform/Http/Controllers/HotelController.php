<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\HotelAdministration;
use App\Domains\Platform\Http\Requests\HotelRequest;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class HotelController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Hotel::class);

        $search = trim((string) $request->query('search', ''));

        $hotels = Hotel::query()
            ->with('owner:id,first_name,last_name,email')
            ->withCount('activeMembers')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Hotel $hotel): array => [
                'id' => $hotel->id,
                'name' => $hotel->name,
                'slug' => $hotel->slug,
                'country_code' => $hotel->country_code,
                'currency' => $hotel->currency,
                'status' => $hotel->status->value,
                'owner' => $hotel->owner?->only(['id', 'name', 'email']),
                'members_count' => $hotel->getAttribute('active_members_count'),
                'created_at' => $hotel->created_at?->toIso8601String(),
            ]);

        return Inertia::render('platform/hotels/index', [
            'hotels' => $hotels,
            'filters' => ['search' => $search],
            'canCreate' => $request->user()?->can('create', Hotel::class) === true,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Hotel::class);

        return Inertia::render('platform/hotels/create', $this->formOptions());
    }

    public function store(HotelRequest $request, HotelAdministration $administration): RedirectResponse
    {
        $hotel = $administration->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Hotel created.')]);

        return to_route('platform.hotels.edit', $hotel);
    }

    public function edit(Request $request, Hotel $hotel): Response
    {
        Gate::authorize('view', $hotel);

        $hotel->load('owner:id,first_name,last_name,email');

        return Inertia::render('platform/hotels/edit', [
            ...$this->formOptions(),
            'hotel' => [
                ...$hotel->only(['id', 'name', 'slug', 'legal_name', 'country_code', 'timezone', 'currency', 'default_locale']),
                'status' => $hotel->status->value,
                'owner_email' => $hotel->owner?->email,
            ],
            'members' => $hotel->members()
                ->orderBy('first_name')
                ->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'status' => $member->getRelationValue('membership')?->getAttribute('status')?->value,
                    'is_owner' => $member->id === $hotel->owner_user_id,
                ])
                ->values(),
            'canUpdate' => $request->user()?->can('update', $hotel) === true,
            'canManageMembers' => $request->user()?->can('manageMembers', $hotel) === true,
        ]);
    }

    public function update(HotelRequest $request, Hotel $hotel, HotelAdministration $administration): RedirectResponse
    {
        $administration->update($hotel, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Hotel updated.')]);

        return to_route('platform.hotels.edit', $hotel);
    }

    /**
     * @return array{statuses: list<string>, locales: list<string>, timezones: list<string>}
     */
    private function formOptions(): array
    {
        return [
            'statuses' => array_map(fn (HotelStatus $status): string => $status->value, HotelStatus::cases()),
            'locales' => HotelRequest::LOCALES,
            'timezones' => timezone_identifiers_list(),
        ];
    }
}
