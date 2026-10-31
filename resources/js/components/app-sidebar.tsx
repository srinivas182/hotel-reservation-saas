import { Link, usePage } from '@inertiajs/react';
import { Building2, LayoutGrid } from 'lucide-react';
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
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const isPlatformStaff = auth.user.platform_role !== null;

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
                <NavMain label="Hotel" items={mainNavItems} />
                {isPlatformStaff && (
                    <NavMain label="Platform admin" items={platformNavItems} />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
