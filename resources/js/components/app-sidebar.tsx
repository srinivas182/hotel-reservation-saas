import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    LayoutGrid,
    Percent,
    BarChart3,
    BedDouble,
    CalendarDays,
    ClipboardList,
    Sun,
    Tags,
    Mail,
    ScrollText,
    Settings2,
    UserCog,
    Users,
} from 'lucide-react';
import HotelAuditController from '@/actions/App/Domains/Audit/Http/Controllers/HotelAuditController';
import UsageController from '@/actions/App/Domains/Messaging/Http/Controllers/UsageController';
import RoomsController from '@/actions/App/Domains/Rooms/Http/Controllers/RoomsController';
import CalendarController from '@/actions/App/Domains/Availability/Http/Controllers/CalendarController';
import BookingController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingController';
import TodayController from '@/actions/App/Domains/Bookings/Http/Controllers/TodayController';
import RatesController from '@/actions/App/Domains/Rates/Http/Controllers/RatesController';
import MailProviderController from '@/actions/App/Domains/Platform/Http/Controllers/MailProviderController';
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
        ...(auth.permissions.includes('rooms.view')
            ? [{ title: 'Rooms', href: RoomsController(), icon: BedDouble }]
            : []),
        ...(auth.permissions.includes('bookings.view')
            ? [{ title: 'Today', href: TodayController.index(), icon: Sun }]
            : []),
        ...(auth.permissions.includes('bookings.view')
            ? [
                  {
                      title: 'Bookings',
                      href: BookingController.index(),
                      icon: ClipboardList,
                  },
              ]
            : []),
        ...(auth.permissions.includes('availability.view')
            ? [
                  {
                      title: 'Availability',
                      href: CalendarController(),
                      icon: CalendarDays,
                  },
              ]
            : []),
        ...(auth.permissions.includes('rates.view')
            ? [{ title: 'Rates', href: RatesController(), icon: Tags }]
            : []),
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
        ...(auth.permissions.includes('usage.view')
            ? [{ title: 'Usage', href: UsageController(), icon: BarChart3 }]
            : []),
    ];
    const platformItems: NavItem[] = [
        ...platformNavItems,
        ...(auth.user.platform_role === 'super_admin'
            ? [
                  {
                      title: 'Email providers',
                      href: MailProviderController.index(),
                      icon: Mail,
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
                        items={platformItems}
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
