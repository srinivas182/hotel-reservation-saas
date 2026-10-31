<?php

declare(strict_types=1);

namespace App\Domains\Access\Http\Controllers;

use App\Domains\Access\Exceptions\StaffRuleViolation;
use App\Domains\Access\HotelRoles;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Permission;
use App\Domains\Audit\Audit;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Custom roles: hotels build their own roles from the permission list.
 * System roles are shown for reference and cannot be changed.
 */
final class RoleController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly HotelRoles $roles,
    ) {}

    public function index(): Response
    {
        $hotel = $this->context->hotel();

        return Inertia::render('staff/roles', [
            'roles' => $this->roles->all($hotel)->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->displayName(),
                'is_system' => $role->is_system,
                'permissions' => $role->permissions->pluck('name')->values(),
                'members_count' => $this->membersCount($role),
            ])->values(),
            'permissions' => collect(Permission::cases())->map(fn (Permission $permission): array => [
                'name' => $permission->value,
                'label' => $permission->label(),
                'group' => $permission->group(),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $hotel = $this->context->hotel();
        $validated = $this->validated($request);

        $role = DB::transaction(function () use ($hotel, $validated): Role {
            /** @var Role $role */
            $role = Role::query()->create([
                'hotel_id' => $hotel->getKey(),
                'name' => $validated['name'],
                'guard_name' => HotelRoles::GUARD,
                'is_system' => false,
            ]);
            $role->syncPermissions($validated['permissions']);

            return $role;
        });

        Audit::record('roles.created', 'Custom role created', subject: $hotel, properties: [
            'role' => $role->name,
            'permissions' => $validated['permissions'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return back();
    }

    public function update(Request $request, int $role): RedirectResponse
    {
        $hotel = $this->context->hotel();
        $model = $this->customRole($role);
        $validated = $this->validated($request, $model);

        $before = $model->permissions->pluck('name')->all();
        $model->update(['name' => $validated['name']]);
        $model->syncPermissions($validated['permissions']);

        Audit::record('roles.updated', 'Custom role updated', subject: $hotel, properties: [
            'role' => $model->name,
            'old' => $before,
            'new' => $validated['permissions'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return back();
    }

    public function destroy(int $role): RedirectResponse
    {
        $hotel = $this->context->hotel();
        $model = $this->customRole($role);

        if ($this->membersCount($model) > 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => StaffRuleViolation::roleInUse()->getMessage()]);

            return back();
        }

        $name = $model->name;
        $model->delete();

        Audit::record('roles.deleted', 'Custom role deleted', subject: $hotel, properties: ['role' => $name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return back();
    }

    private function customRole(int $id): Role
    {
        $role = $this->roles->find($this->context->hotel(), $id);

        abort_if($role === null, 404);
        abort_if($role->is_system, 403, __('System roles cannot be changed.'));

        return $role;
    }

    private function membersCount(Role $role): int
    {
        return DB::table('model_has_roles')->where('role_id', $role->getKey())->count();
    }

    /**
     * @return array{name: string, permissions: list<string>}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        $hotelId = $this->context->id();

        /** @var array{name: string, permissions?: list<string>} $data */
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::notIn(array_map(fn ($r) => $r->value, \App\Domains\Access\SystemRole::cases())),
                Rule::unique('roles', 'name')
                    ->where('hotel_id', $hotelId)
                    ->ignore($role?->getKey()),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ]);

        return [
            'name' => trim($data['name']),
            'permissions' => array_values(array_unique($data['permissions'] ?? [])),
        ];
    }
}
