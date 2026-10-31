<?php

declare(strict_types=1);

namespace App\Domains\Access;

/**
 * Roles copied into every hotel. They cannot be edited by hotels; their
 * permissions are maintained here and applied by `permissions:sync`.
 */
enum SystemRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Reception = 'reception';
    case RestaurantBar = 'restaurant_bar';
    case EventsCoordinator = 'events_coordinator';
    case ReadOnly = 'read_only';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Manager => __('Manager'),
            self::Reception => __('Reception'),
            self::RestaurantBar => __('Restaurant & Bar'),
            self::EventsCoordinator => __('Events coordinator'),
            self::ReadOnly => __('Read-only'),
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Manager => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => $permission !== Permission::RolesManage,
            )),
            self::Reception => [Permission::StaffView],
            self::RestaurantBar, self::EventsCoordinator => [],
            self::ReadOnly => [
                Permission::HotelSettingsView,
                Permission::TaxesView,
                Permission::StaffView,
                Permission::AuditView,
            ],
        };
    }
}
