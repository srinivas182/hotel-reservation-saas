<?php

declare(strict_types=1);

namespace App\Domains\Access;

use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Models\Hotel;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates and maintains each hotel's roles.
 */
final class HotelRoles
{
    public const GUARD = 'web';

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * Create all permissions and make every hotel's system roles match the
     * code definitions. Safe to run repeatedly.
     */
    public function sync(): void
    {
        foreach (Permission::values() as $name) {
            PermissionModel::findOrCreate($name, self::GUARD);
        }

        PermissionModel::query()
            ->where('guard_name', self::GUARD)
            ->whereNotIn('name', Permission::values())
            ->delete();

        Hotel::withTrashed()->orderBy('id')->each(fn (Hotel $hotel) => $this->provision($hotel));

        $this->registrar->forgetCachedPermissions();
    }

    /**
     * Make sure the hotel has every system role with its current permissions.
     */
    public function provision(Hotel $hotel): void
    {
        $this->ensurePermissionsExist();

        foreach (SystemRole::cases() as $systemRole) {
            /** @var Role $role */
            $role = Role::query()->firstOrCreate(
                ['hotel_id' => $hotel->getKey(), 'name' => $systemRole->value, 'guard_name' => self::GUARD],
                ['is_system' => true],
            );

            $role->syncPermissions(array_map(fn (Permission $p): string => $p->value, $systemRole->permissions()));
        }

        $this->registrar->forgetCachedPermissions();
    }

    public function find(Hotel $hotel, int $roleId): ?Role
    {
        return Role::query()->where('hotel_id', $hotel->getKey())->whereKey($roleId)->first();
    }

    public function system(Hotel $hotel, SystemRole $systemRole): Role
    {
        return Role::query()
            ->where('hotel_id', $hotel->getKey())
            ->where('name', $systemRole->value)
            ->where('is_system', true)
            ->firstOrFail();
    }

    /**
     * System roles first (in definition order), then custom roles by name.
     *
     * @return Collection<int, Role>
     */
    public function all(Hotel $hotel): Collection
    {
        $order = array_flip(array_map(fn (SystemRole $role): string => $role->value, SystemRole::cases()));

        return Role::query()
            ->where('hotel_id', $hotel->getKey())
            ->with('permissions:id,name')
            ->get()
            ->sortBy(fn (Role $role): string => $role->is_system
                ? sprintf('0-%02d', $order[$role->name] ?? 99)
                : '1-'.mb_strtolower($role->name))
            ->values();
    }

    private function ensurePermissionsExist(): void
    {
        $existing = PermissionModel::query()->where('guard_name', self::GUARD)->pluck('name')->all();

        foreach (array_diff(Permission::values(), $existing) as $missing) {
            PermissionModel::findOrCreate($missing, self::GUARD);
        }
    }
}
