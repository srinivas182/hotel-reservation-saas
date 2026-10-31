<?php

declare(strict_types=1);

namespace App\Domains\Access\Models;

use App\Domains\Access\SystemRole;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A hotel role (spatie/laravel-permission, teams mode: hotel_id is the team).
 *
 * Roles are scoped by the permission package's team ID, which is kept equal
 * to the current hotel by TenantContext; all role management goes through
 * HotelRoles, which filters by hotel explicitly.
 *
 * @property int $id
 * @property string|null $hotel_id
 * @property string $name
 * @property bool $is_system
 */
class Role extends SpatieRole
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function systemRole(): ?SystemRole
    {
        return $this->is_system ? SystemRole::tryFrom($this->name) : null;
    }

    public function isOwner(): bool
    {
        return $this->systemRole() === SystemRole::Owner;
    }

    public function displayName(): string
    {
        return $this->systemRole()?->label() ?? $this->name;
    }
}
