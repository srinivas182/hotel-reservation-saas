<?php

declare(strict_types=1);

namespace App\Domains\Workspace;

use App\Domains\Access\Models\Role;
use App\Domains\Access\StaffService;
use App\Domains\Access\SystemRole;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\TenantContext;
use App\Domains\Workspace\Models\StaffPreference;
use App\Models\User;
use App\Support\RequestMemo;
use Illuminate\Support\Facades\Session;

/**
 * The dashboard as each person sees it (Sprint E): simple or full mode
 * (by role, or their own choice), larger text, finished tours, and the
 * owner's "view as" preview of another role.
 */
final class Workspace
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffService $staff,
    ) {}

    /**
     * Read once per web request: the dashboard is drawn once per page.
     *
     * @template T
     *
     * @param  \Closure(): T  $compute
     * @return T
     */
    private function remember(string $key, \Closure $compute): mixed
    {
        return RequestMemo::remember('workspace|'.$this->context->id().'|'.$key, $compute);
    }

    /**
     * Roles that open the simple dashboard. By default reception only.
     *
     * @return list<int>
     */
    public function simpleRoleIds(): array
    {
        /** @var list<int> */
        return $this->remember('simple-roles', fn (): array => $this->computeSimpleRoleIds());
    }

    /**
     * @return list<int>
     */
    private function computeSimpleRoleIds(): array
    {
        $chosen = HotelSettings::current()->simple_mode_roles;

        if ($chosen !== null) {
            return array_map('intval', $chosen);
        }

        // System roles never change id, so this lookup is cached.
        /** @var list<int> */
        return \Illuminate\Support\Facades\Cache::remember('workspace.reception-role.'.$this->context->id(), now()->addDay(), fn (): array => array_values(array_map('intval', Role::query()
            ->where('is_system', true)->where('name', SystemRole::Reception->value)
            ->where(fn ($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', $this->context->id()))
            ->pluck('id')->all())));
    }

    /**
     * The role whose dashboard the owner is previewing, if any. Only people
     * who manage staff may preview, and only their own hotel's roles.
     */
    public function viewAs(User $user): ?Role
    {
        /** @var Role|null */
        return $this->remember('view-as|'.$user->getKey(), fn (): ?Role => $this->computeViewAs($user));
    }

    private function computeViewAs(User $user): ?Role
    {
        $id = Session::get($this->viewAsKey());

        if ($id === null || ! $user->can('staff.manage')) {
            return null;
        }

        return Role::query()->whereKey($id)->where(fn ($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', $this->context->id()))->first();
    }

    public function startViewAs(Role $role): void
    {
        RequestMemo::forget('workspace|');
        Session::put($this->viewAsKey(), $role->id);
    }

    public function stopViewAs(): void
    {
        RequestMemo::forget('workspace|');
        Session::forget($this->viewAsKey());
    }

    /**
     * Permissions to show in the dashboard: the previewed role's, else the
     * person's own. The server still checks the person's own on every action.
     *
     * @return list<string>|null null: no preview
     */
    public function previewPermissions(User $user): ?array
    {
        $role = $this->viewAs($user);

        return $role === null ? null : array_values(array_map('strval', $role->permissions()->pluck('name')->all()));
    }

    public function isSimple(User $user): bool
    {
        $preview = $this->viewAs($user);

        if ($preview !== null) {
            return in_array($preview->id, $this->simpleRoleIds(), true);
        }

        $mode = $this->preference($user)->ui_mode;

        if ($mode !== null) {
            return $mode === 'simple';
        }

        // Inside the hotel the user's roles are those of this hotel, and the
        // permission checks have usually loaded them already.
        $role = $user->relationLoaded('roles') ? $user->roles->first() : $this->staff->roleOf($this->context->hotel(), $user);

        return $role instanceof Role && in_array($role->id, $this->simpleRoleIds(), true);
    }

    /**
     * What every page needs to draw the dashboard for this person.
     *
     * @return array{simple: bool, mode: string|null, largeText: bool, toursDone: list<string>, memberSince: string|null, viewAs: array{id: int, name: string}|null, canViewAs: bool}
     */
    public function share(User $user): array
    {
        $preference = $this->preference($user);
        $preview = $this->viewAs($user);
        // The memberships are usually loaded already by the tenancy middleware.
        $pivot = $user->activeHotels->firstWhere('id', $this->context->id())?->getRelation('membership');
        $since = $pivot?->getAttribute('joined_at') ?? $pivot?->getAttribute('created_at');

        return [
            'simple' => $this->isSimple($user),
            'mode' => $preference->ui_mode,
            'largeText' => $preference->large_text,
            'toursDone' => $preference->tours_done ?? [],
            'memberSince' => $since === null ? null : \Carbon\CarbonImmutable::parse($since)->toDateString(),
            'viewAs' => $preview === null ? null : ['id' => $preview->id, 'name' => $preview->displayName()],
            'canViewAs' => $user->can('staff.manage'),
        ];
    }

    private function preference(User $user): StaffPreference
    {
        /** @var StaffPreference */
        return $this->remember('preference|'.$user->getKey(), fn (): StaffPreference => StaffPreference::for($user));
    }

    private function viewAsKey(): string
    {
        return 'workspace.view_as.'.$this->context->id();
    }
}
