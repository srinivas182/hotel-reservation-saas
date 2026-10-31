import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    LayoutGrid,
    Percent,
    ScrollText,
    Settings2,
    UserCog,
    Users,
} from 'lucide-react';
import HotelAuditController from '@/actions/App/Domains/Audit/Http/Controllers/HotelAuditController';
import UserController from '@/actions/App/Domains/Platform/Http/Controllers/UserController';
import TaxRateController from '@/actions/App/Domains/Finance/Http/Controllers/TaxRateController';
import HotelSettingsController from '@/actions/App/Domains/Hotel/Http/Controllers/HotelSettingsController';
import StaffController from '@/actions/App/Domains/Access/Http/Controllers/StaffController';
import HotelController from '@/actions/App/Domains/Platform/Http/Controllers/HotelController';
import AppLogo from '@/components/app-logo';
import { HotelSwitcher } from '@/components/hotel-switcher';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';
import { t } from '@/lib/i18n';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const platformNavItems: NavItem[] = [
    {
        title: 'Hotels',
        href: HotelController.index(),
        icon: Building2,
    },
    {
        title: 'Users',
        href: UserController.index(),
        icon: UserCog,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const isPlatformStaff = auth.user.platform_role !== null;
    const hotelNavItems: NavItem[] = [
        ...mainNavItems,
        ...(auth.permissions.includes('staff.view')
            ? [{ title: 'Staff', href: StaffController.index(), icon: Users }]
            : []),
        ...(auth.permissions.includes('hotel.settings.view')
            ? [
                  {
                      title: 'Hotel settings',
                      href: HotelSettingsController.edit(),
                      icon: Settings2,
                  },
              ]
            : []),
        ...(auth.permissions.includes('taxes.view')
            ? [
                  {
                      title: 'Taxes',
                      href: TaxRateController.index(),
                      icon: Percent,
                  },
              ]
            : []),
        ...(auth.permissions.includes('audit.view')
            ? [
                  {
                      title: 'Audit log',
                      href: HotelAuditController(),
                      icon: ScrollText,
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <HotelSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain label={t('Hotel')} items={hotelNavItems} />
                {isPlatformStaff && (
                    <NavMain
                        label={t('Platform admin')}
                        items={platformNavItems}
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
