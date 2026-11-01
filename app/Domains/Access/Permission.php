<?php

declare(strict_types=1);

namespace App\Domains\Access;

/**
 * Every permission a hotel role can hold. Each sprint adds the permissions
 * for its module here; `php artisan permissions:sync` creates them and
 * updates the system roles (it runs on every deployment).
 */
enum Permission: string
{
    case HotelSettingsView = 'hotel.settings.view';
    case HotelSettingsManage = 'hotel.settings.manage';
    case StaffView = 'staff.view';
    case StaffInvite = 'staff.invite';
    case StaffManage = 'staff.manage';
    case RolesManage = 'roles.manage';
    case TaxesView = 'taxes.view';
    case TaxesManage = 'taxes.manage';
    case AuditView = 'audit.view';
    case UsageView = 'usage.view';
    case RoomsView = 'rooms.view';
    case RoomsManage = 'rooms.manage';
    case RatesView = 'rates.view';
    case RatesManage = 'rates.manage';
    case AvailabilityView = 'availability.view';
    case AvailabilityManage = 'availability.manage';

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    public function label(): string
    {
        return match ($this) {
            self::HotelSettingsView => __('View hotel settings'),
            self::HotelSettingsManage => __('Change hotel settings'),
            self::StaffView => __('View staff'),
            self::StaffInvite => __('Invite staff'),
            self::StaffManage => __('Change roles, suspend and remove staff'),
            self::RolesManage => __('Create and edit custom roles'),
            self::TaxesView => __('View taxes'),
            self::TaxesManage => __('Change taxes'),
            self::AuditView => __('View the audit log'),
            self::UsageView => __('View usage'),
            self::RoomsView => __('View rooms'),
            self::RoomsManage => __('Change room categories, rooms and closures'),
            self::RatesView => __('View rates'),
            self::RatesManage => __('Change rates'),
            self::AvailabilityView => __('View availability and quotes'),
            self::AvailabilityManage => __('Stop sales, hold back rooms and place holds'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
