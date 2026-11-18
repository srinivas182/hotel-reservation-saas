<?php

declare(strict_types=1);

namespace App\Domains\Workspace\Http\Controllers;

use App\Domains\Access\Models\Role;
use App\Domains\Audit\Audit;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Workspace\Models\ShiftChecklist;
use App\Domains\Workspace\Models\ShiftChecklistTick;
use App\Domains\Workspace\ShiftChecklists;
use App\Domains\Workspace\Workspace;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Simple mode and checklists (Sprint E): which roles open the
 * simple dashboard, and the shift checklists. Ticking a task is done from
 * Today by anyone on shift.
 */
final class ShiftChecklistController extends Controller
{
    public function index(Workspace $workspace): Response
    {
        $hotelId = app(\App\Domains\Tenancy\TenantContext::class)->id();

        return Inertia::render('hotel/workspace-settings', [
            'roles' => Role::query()->where(fn ($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', $hotelId))->orderBy('id')->get()
                ->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->displayName(), 'owner' => $role->isOwner()])->values(),
            'simpleRoles' => $workspace->simpleRoleIds(),
            'checklists' => ShiftChecklist::query()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (ShiftChecklist $c): array => ['id' => $c->id, 'name' => $c->name, 'shift' => $c->shift, 'items' => $c->items, 'active' => $c->active])->values(),
        ]);
    }

    public function simpleRoles(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['roles' => ['present', 'array'], 'roles.*' => ['integer']]);
        $hotelId = app(\App\Domains\Tenancy\TenantContext::class)->id();
        $valid = Role::query()->whereIn('id', $data['roles'])->where(fn ($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', $hotelId))->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        HotelSettings::current()->forceFill(['simple_mode_roles' => $valid])->save();
        Audit::record('workspace.simple_roles_changed', 'Roles with the simple dashboard changed', causer: $user, properties: ['roles' => $valid]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $checklist = ShiftChecklist::query()->create($this->validated($request) + ['sort_order' => (int) ShiftChecklist::query()->max('sort_order') + 1]);
        Audit::record('workspace.checklist_created', 'Shift checklist created', causer: $user, properties: ['name' => $checklist->name]);

        return back();
    }

    public function update(Request $request, ShiftChecklist $checklist, #[CurrentUser] User $user): RedirectResponse
    {
        $checklist->update($this->validated($request) + ['active' => $request->boolean('active', true)]);
        Audit::record('workspace.checklist_updated', 'Shift checklist changed', causer: $user, properties: ['name' => $checklist->name]);

        return back();
    }

    public function destroy(ShiftChecklist $checklist, #[CurrentUser] User $user): RedirectResponse
    {
        Audit::record('workspace.checklist_deleted', 'Shift checklist deleted', causer: $user, properties: ['name' => $checklist->name]);
        $checklist->delete();

        return back();
    }

    public function tick(Request $request, ShiftChecklist $checklist, ShiftChecklists $lists, #[CurrentUser] User $user): RedirectResponse
    {
        $keys = array_map(fn (string $item): string => ShiftChecklist::itemKey($item), $checklist->items);
        $data = $request->validate(['key' => ['required', 'string', Rule::in($keys)], 'done' => ['required', 'boolean']]);
        $day = $lists->today()->toDateString();

        if ((bool) $data['done']) {
            ShiftChecklistTick::query()->firstOrCreate(['shift_checklist_id' => $checklist->id, 'day' => $day, 'item_key' => $data['key']], ['user_id' => $user->getKey()]);
        } else {
            ShiftChecklistTick::query()->where('shift_checklist_id', $checklist->id)->where('day', $day)->where('item_key', $data['key'])->delete();
        }

        return back();
    }

    /**
     * @return array{name: string, shift: string, items: list<string>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'shift' => ['required', Rule::in(ShiftChecklist::SHIFTS)],
            'items' => ['required', 'array', 'min:1', 'max:'.ShiftChecklist::MAX_ITEMS],
            'items.*' => ['required', 'string', 'max:160', 'distinct:ignore_case'],
        ]);

        return ['name' => trim($data['name']), 'shift' => $data['shift'], 'items' => array_values(array_map(fn (string $i): string => trim($i), $data['items']))];
    }
}
