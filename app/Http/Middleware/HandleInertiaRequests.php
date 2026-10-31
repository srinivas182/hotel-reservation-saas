<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'permissions' => fn (): array => $this->permissions($request),
            ],
            'tenancy' => fn (): array => $this->tenancy($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Current hotel and the hotels the user can switch to.
     *
     * @return array{current: array{id: string, name: string, slug: string}|null, hotels: list<array{id: string, name: string, slug: string}>}
     */
    private function tenancy(Request $request): array
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        $present = fn (Hotel $hotel): array => [
            'id' => (string) $hotel->getKey(),
            'name' => $hotel->name,
            'slug' => $hotel->slug,
        ];

        return [
            'current' => $context->has() ? $present($context->hotel()) : null,
            'hotels' => $user instanceof User
                ? array_values(app(HotelMembershipService::class)->accessibleHotels($user)->map($present)->all())
                : [],
        ];
    }

    /**
     * Permission names the user holds in the current hotel (for hiding
     * controls; the server still authorises every action).
     *
     * @return list<string>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User || ! app(TenantContext::class)->has()) {
            return [];
        }

        $user->unsetRelation('roles')->unsetRelation('permissions');

        return array_values($user->getAllPermissions()->pluck('name')->all());
    }
}
