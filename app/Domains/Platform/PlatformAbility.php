<?php

declare(strict_types=1);

namespace App\Domains\Platform;

use App\Domains\Identity\Enums\PlatformRole;

/**
 * What each platform role may do. Every platform route checks one of
 * these; the super admin may do everything.
 */
enum PlatformAbility: string
{
    case HotelsView = 'hotels.view';
    case HotelsManage = 'hotels.manage';
    case BillingView = 'billing.view';
    case BillingManage = 'billing.manage';
    case Catalog = 'catalog';
    case SignupsView = 'signups.view';
    case SignupCodes = 'signups.codes';
    case SignupsConfigure = 'signups.configure';
    case GoLive = 'golive';
    case Readiness = 'readiness';
    case AuditView = 'audit.view';
    case AuditExport = 'audit.export';
    case Notes = 'notes';
    case Configuration = 'configuration';
    case Leads = 'leads';
    case Website = 'website';
    case Help = 'help';
    case Support = 'support';
    case Announcements = 'announcements';

    /**
     * @return list<PlatformRole>
     */
    public function roles(): array
    {
        $ops = PlatformRole::Operations;
        $finance = PlatformRole::Finance;
        $sales = PlatformRole::Sales;

        return [PlatformRole::SuperAdmin, ...match ($this) {
            self::HotelsView, self::Notes => [$ops, $finance, $sales],
            self::HotelsManage, self::GoLive, self::Readiness, self::AuditView, self::Support, self::Announcements => [$ops],
            self::BillingView => [$ops, $finance],
            self::BillingManage => [$finance],
            self::SignupsView => [$ops, $sales],
            self::SignupCodes => [$ops, $sales],
            self::Leads, self::Website => [$sales],
            self::Help => [$ops, $sales],
            self::Catalog, self::SignupsConfigure, self::AuditExport, self::Configuration => [],
        }];
    }

    /**
     * @return list<string>
     */
    public static function for(?PlatformRole $role): array
    {
        if ($role === null) {
            return [];
        }

        return array_values(array_map(fn (self $a): string => $a->value, array_filter(self::cases(), fn (self $a): bool => in_array($role, $a->roles(), true))));
    }
}
