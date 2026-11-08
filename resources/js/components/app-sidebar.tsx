import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    LayoutGrid,
    MessageCircle,
    PartyPopper,
    Rocket,
    Globe,
    Target,
    ShieldCheck,
    Gauge,
    LifeBuoy,
    ToggleRight,
    Megaphone,
    CreditCard,
    UserPlus,
    UtensilsCrossed,
    Sparkles,
    Percent,
    BarChart3,
    Activity,
    BedDouble,
    BellRing,
    Bot,
    CalendarDays,
    ClipboardList,
    Inbox,
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
import AlertSettingsController from '@/actions/App/Domains/StaffAlerts/Http/Controllers/AlertSettingsController';
import EventController from '@/actions/App/Domains/Events/Http/Controllers/EventController';
import GoLiveController from '@/actions/App/Domains/GoLive/Http/Controllers/GoLiveController';
import BillingController from '@/actions/App/Domains/Billing/Http/Controllers/BillingController';
import AnnouncementController from '@/actions/App/Domains/Platform/Http/Controllers/AnnouncementController';
import FeatureController from '@/actions/App/Domains/Platform/Http/Controllers/FeatureController';
import SupportSessionController from '@/actions/App/Domains/Platform/Http/Controllers/SupportSessionController';
import GuestDataController from '@/actions/App/Domains/Privacy/Http/Controllers/GuestDataController';
import OperationsController from '@/actions/App/Domains/Platform/Http/Controllers/OperationsController';
import AuditLogController from '@/actions/App/Domains/Platform/Http/Controllers/AuditLogController';
import PlatformHomeController from '@/actions/App/Domains/Platform/Http/Controllers/PlatformHomeController';
import PlatformHelpController from '@/actions/App/Domains/Help/Http/Controllers/PlatformHelpController';
import PlatformSiteController from '@/actions/App/Domains/Site/Http/Controllers/PlatformSiteController';
import PlatformSignupController from '@/actions/App/Domains/Onboarding/Http/Controllers/PlatformSignupController';
import PlatformBillingController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformBillingController';
import PilotDashboardController from '@/actions/App/Domains/Platform/Http/Controllers/PilotDashboardController';
import RestaurantController from '@/actions/App/Domains/Restaurant/Http/Controllers/RestaurantController';
import ReadinessController from '@/actions/App/Domains/Platform/Http/Controllers/ReadinessController';
import AssistantSettingsController from '@/actions/App/Domains/Assistant/Http/Controllers/AssistantSettingsController';
import CalendarController from '@/actions/App/Domains/Availability/Http/Controllers/CalendarController';
import BookingController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingController';
import TodayController from '@/actions/App/Domains/Bookings/Http/Controllers/TodayController';
import InboxController from '@/actions/App/Domains/Conversations/Http/Controllers/InboxController';
import RatesController from '@/actions/App/Domains/Rates/Http/Controllers/RatesController';
import MailProviderController from '@/actions/App/Domains/Platform/Http/Controllers/MailProviderController';
import AiController from '@/actions/App/Domains/Platform/Http/Controllers/AiController';
import UserController from '@/actions/App/Domains/Platform/Http/Controllers/UserController';
import WhatsAppPlatformController from '@/actions/App/Domains/Platform/Http/Controllers/WhatsAppPlatformController';
import WhatsAppConnectionController from '@/actions/App/Domains/WhatsApp/Http/Controllers/WhatsAppConnectionController';
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

const platformNavItems: (NavItem & { ability: string })[] = [
    {
        title: 'Platform home',
        href: PlatformHomeController(),
        icon: LayoutGrid,
        ability: 'hotels.view',
    },
    {
        title: 'Hotels',
        href: HotelController.index(),
        icon: Building2,
        ability: 'hotels.view',
    },
    {
        title: 'Users',
        href: UserController.index(),
        icon: UserCog,
        ability: 'configuration',
    },
    {
        title: 'WhatsApp',
        href: WhatsAppPlatformController(),
        icon: MessageCircle,
        ability: 'readiness',
    },
    {
        title: 'Go-live readiness',
        href: ReadinessController.index(),
        icon: Rocket,
        ability: 'readiness',
    },
    {
        title: 'Pilot dashboard',
        href: PilotDashboardController(),
        icon: Activity,
        ability: 'readiness',
    },
    {
        title: 'Billing',
        href: PlatformBillingController.index(),
        icon: CreditCard,
        ability: 'billing.view',
    },
    {
        title: 'Sign-ups',
        href: PlatformSignupController.index(),
        icon: UserPlus,
        ability: 'signups.view',
    },
    {
        title: 'Leads',
        href: PlatformSiteController.leads(),
        icon: Target,
        ability: 'leads',
    },
    {
        title: 'Website',
        href: PlatformSiteController.website(),
        icon: Globe,
        ability: 'website',
    },
    {
        title: 'Help centre',
        href: PlatformHelpController.index(),
        icon: LifeBuoy,
        ability: 'help',
    },
    {
        title: 'Audit log',
        href: AuditLogController.index(),
        icon: ScrollText,
        ability: 'audit.view',
    },
    {
        title: 'Operations',
        href: OperationsController.index(),
        icon: Gauge,
        ability: 'readiness',
    },
    {
        title: 'Announcements',
        href: AnnouncementController.index(),
        icon: Megaphone,
        ability: 'announcements',
    },
    {
        title: 'Feature switches',
        href: FeatureController.index(),
        icon: ToggleRight,
        ability: 'configuration',
    },
    {
        title: 'Email providers',
        href: MailProviderController.index(),
        icon: Mail,
        ability: 'configuration',
    },
    {
        title: 'AI',
        href: AiController.index(),
        icon: Sparkles,
        ability: 'configuration',
    },
];

export function AppSidebar() {
    const { auth, inboxUnread, modules = [] } = usePage().props;
    const isPlatformStaff = auth.user.platform_role !== null;
    const hotelNavItems: NavItem[] = [
        ...mainNavItems,
        ...(auth.permissions.includes('rooms.view')
            ? [{ title: 'Rooms', href: RoomsController(), icon: BedDouble }]
            : []),
        ...(auth.permissions.includes('bookings.view')
            ? [{ title: 'Today', href: TodayController.index(), icon: Sun }]
            : []),
        ...(auth.permissions.includes('hotel.settings.manage')
            ? [
                  {
                      title: 'Guest data requests',
                      href: GuestDataController.index(),
                      icon: ShieldCheck,
                  },
              ]
            : []),
        ...(auth.permissions.includes('conversations.view')
            ? [
                  {
                      title: 'Inbox',
                      href: InboxController.index(),
                      icon: Inbox,
                      badge: inboxUnread ?? 0,
                  },
              ]
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
        ...(auth.permissions.includes('staff.manage')
            ? [
                  {
                      title: 'Support access',
                      href: SupportSessionController.index(),
                      icon: LifeBuoy,
                  },
              ]
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
        ...(auth.permissions.includes('events.view') &&
        modules.includes('events')
            ? [
                  {
                      title: 'Events',
                      href: EventController.index(),
                      icon: PartyPopper,
                  },
              ]
            : []),
        ...(auth.permissions.includes('billing.view')
            ? [
                  {
                      title: 'Plan and credits',
                      href: BillingController(),
                      icon: CreditCard,
                  },
              ]
            : []),
        ...(auth.permissions.includes('restaurant.view') &&
        modules.includes('restaurant')
            ? [
                  {
                      title: 'Restaurant & Bar',
                      href: RestaurantController(),
                      icon: UtensilsCrossed,
                  },
              ]
            : []),
        ...(auth.permissions.includes('conversations.reply') ||
        auth.permissions.includes('booking_requests.decide')
            ? [
                  {
                      title: 'My alerts',
                      href: AlertSettingsController.show(),
                      icon: BellRing,
                  },
              ]
            : []),
        ...(auth.permissions.includes('assistant.manage')
            ? [{ title: 'Go-live', href: GoLiveController(), icon: Rocket }]
            : []),
        ...(auth.permissions.includes('assistant.manage')
            ? [
                  {
                      title: 'AI assistant',
                      href: AssistantSettingsController.show(),
                      icon: Bot,
                  },
              ]
            : []),
        ...(auth.permissions.includes('whatsapp.connect')
            ? [
                  {
                      title: 'WhatsApp',
                      href: WhatsAppConnectionController.show(),
                      icon: MessageCircle,
                  },
              ]
            : []),
    ];
    const abilities = auth.platform ?? [];
    const platformItems: NavItem[] = platformNavItems.filter((item) =>
        abilities.includes(item.ability),
    );

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
