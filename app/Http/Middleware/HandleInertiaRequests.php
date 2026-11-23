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
            'brandLogo' => fn (): ?string => \App\Domains\Site\Models\BrandSettings::cached()['logo'] ?? null,
            // Sprint I: the accent colours (hotel's own, else the platform's) and the sign-in photo.
            'palette' => fn (): array => \App\Support\BrandPalette::for(\App\Support\BrandPalette::currentColor()),
            'brand' => fn (): array => [
                'loginPhoto' => \App\Domains\Site\Models\BrandSettings::cached()['login_photo'] ?? null,
                'tagline' => (\App\Domains\Site\Models\BrandSettings::cached()['tagline'] ?? [])[app()->getLocale()] ?? null,
            ],
            'locale' => app()->getLocale(),
            'locales' => Locales::SUPPORTED,
            'auth' => [
                'user' => $request->user(),
                'permissions' => fn (): array => $this->permissions($request),
                'platform' => fn (): array => \App\Domains\Platform\PlatformAbility::for($request->user()?->platform_role),
            ],
            'tenancy' => fn (): array => $this->tenancy($request),
            'inboxUnread' => fn (): int => $this->inboxUnread($request),
            // 0.37.2: work waiting for staff, as red counts in the menu.
            'actionCounts' => fn (): array => $this->actionCounts($request),
            'whatsNewUnread' => fn (): bool => $request->user() instanceof User && \App\Domains\Product\Models\ReleaseNote::query()->published()->when($request->user()->whats_new_seen_at !== null, fn ($q) => $q->where('published_at', '>', $request->user()->whats_new_seen_at))->exists(),
            'demoHotel' => fn (): bool => app(TenantContext::class)->has() && app(TenantContext::class)->hotel()->is_demo,
            'supportSession' => function (): ?array {
                $s = app(\App\Domains\Platform\Support\SupportAccess::class)->current();

                return $s === null ? null : ['id' => $s->id, 'mode' => $s->mode, 'expires_at' => $s->expires_at?->toIso8601String(), 'extended' => $s->extended, 'hotel' => $s->hotel?->name];
            },
            // I: an incident banner in the app, linking to the status page.
            'statusNotices' => fn (): array => $request->user() instanceof User
                ? \App\Domains\Status\Models\StatusNotice::query()->active()->orderByDesc('starts_at')->get()
                    ->filter(fn (\App\Domains\Status\Models\StatusNotice $n): bool => ! app(TenantContext::class)->has() || $n->concerns(app(TenantContext::class)->hotel()->country_code))
                    ->map(fn (\App\Domains\Status\Models\StatusNotice $n): array => ['id' => $n->id, 'level' => $n->level, 'title' => $n->text(app()->getLocale())])->values()->all()
                : [],
            'announcements' => fn (): array => $request->user() instanceof User ? app(\App\Domains\Platform\Announcements\Announcements::class)->forCurrent($request->user(), app()->getLocale()) : [],
            'billingReduced' => fn (): bool => \App\Domains\Billing\Invoices\Dunning::isReduced(),
            'modules' => fn (): array => app(TenantContext::class)->has() ? app(\App\Domains\Billing\Entitlements::class)->modules() : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Sprint E: simple mode, larger text, tours, "view as".
            'workspace' => fn (): ?array => $request->user() instanceof User && app(TenantContext::class)->has()
                ? app(\App\Domains\Workspace\Workspace::class)->share($request->user())
                : null,
            // Sprint D: the staff app (notifications, icon badge).
            'push' => fn (): ?array => $request->user() instanceof User && app(TenantContext::class)->has() && filled(config('webpush.public_key'))
                ? ['key' => (string) config('webpush.public_key')]
                : null,
            'appBadge' => fn (): int => $request->user() instanceof User && app(TenantContext::class)->has() && $request->user()->can('conversations.view')
                ? \App\Domains\Push\Badge::count()
                : 0,
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
     * Things staff must act on, counted for the menu: bank transfer
     * receipts to check (Deposits) and booking requests to accept or
     * decline (Today). Each only for those allowed to act on it.
     *
     * @return array{deposits: int, requests: int}
     */
    private function actionCounts(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User || ! app(TenantContext::class)->has()) {
            return ['deposits' => 0, 'requests' => 0];
        }

        $deposits = $user->can('folio.payments');
        $requests = $user->can('booking_requests.decide');
        if (! $deposits && ! $requests) {
            return ['deposits' => 0, 'requests' => 0];
        }

        // One query for both counts: this runs on every screen.
        $query = \Illuminate\Support\Facades\DB::query();
        if ($deposits) {
            $query->selectSub(\App\Domains\Deposits\Models\DepositAttempt::query()->selectRaw('count(*)')->where('method', 'bank_transfer')->where('status', 'pending')->whereNotNull('proof_at'), 'deposits');
        }
        if ($requests) {
            $query->selectSub(\App\Domains\BookingRequests\BookingRequest::query()->selectRaw('count(*)')->where('status', \App\Domains\BookingRequests\BookingRequestStatus::Pending->value), 'requests');
        }
        $row = (array) $query->first();

        return ['deposits' => (int) ($row['deposits'] ?? 0), 'requests' => (int) ($row['requests'] ?? 0)];
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

        // Sprint E: the owner previewing another role sees that role's dashboard (the server
        // still checks the owner's own permissions on every action).
        $preview = app(\App\Domains\Workspace\Workspace::class)->previewPermissions($user);
        if ($preview !== null) {
            return $preview;
        }

        $user->unsetRelation('roles')->unsetRelation('permissions');

        return array_values($user->getAllPermissions()->pluck('name')->all());
    }
}
