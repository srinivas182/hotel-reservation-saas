<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Hotel\Support\Locales;
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
            'locale' => app()->getLocale(),
            'locales' => Locales::SUPPORTED,
            'auth' => [
                'user' => $request->user(),
                'permissions' => fn (): array => $this->permissions($request),
                'platform' => fn (): array => \App\Domains\Platform\PlatformAbility::for($request->user()?->platform_role),
            ],
            'tenancy' => fn (): array => $this->tenancy($request),
            'inboxUnread' => fn (): int => $this->inboxUnread($request),
            'supportSession' => function (): ?array {
                $s = app(\App\Domains\Platform\Support\SupportAccess::class)->current();

                return $s === null ? null : ['id' => $s->id, 'mode' => $s->mode, 'expires_at' => $s->expires_at?->toIso8601String(), 'extended' => $s->extended, 'hotel' => $s->hotel?->name];
            },
            'announcements' => fn (): array => $request->user() instanceof User ? app(\App\Domains\Platform\Announcements\Announcements::class)->forCurrent($request->user(), app()->getLocale()) : [],
            'billingReduced' => fn (): bool => \App\Domains\Billing\Invoices\Dunning::isReduced(),
            'modules' => fn (): array => app(TenantContext::class)->has() ? app(\App\Domains\Billing\Entitlements::class)->modules() : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Conversations with unread guest messages (sidebar badge).
     */
    private function inboxUnread(Request $request): int
    {
        if (! app(TenantContext::class)->has() || $request->user()?->can('conversations.view') !== true) {
            return 0;
        }

        return \App\Domains\Conversations\Models\Conversation::query()->where('unread_count', '>', 0)->count();
    }

    /**
     * Current hotel and the hotels the user can switch to.
     *
     * @return array{current: array<string, string>|null, hotels: list<array<string, string>>}
     */
    private function tenancy(Request $request): array
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        $present = fn (Hotel $hotel): array => [
            'id' => (string) $hotel->getKey(),
            'name' => $hotel->name,
            'slug' => $hotel->slug,
            'timezone' => $hotel->timezone,
            'currency' => $hotel->currency,
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

        $support = app(\App\Domains\Platform\Support\SupportAccess::class);
        if ($support->current() !== null) {
            return $support->permissions();
        }

        $user->unsetRelation('roles')->unsetRelation('permissions');

        return array_values($user->getAllPermissions()->pluck('name')->all());
    }
}
