import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import SwitchHotelController from '@/actions/App/Domains/Tenancy/Http/Controllers/SwitchHotelController';

/**
 * Shows the current hotel and lets staff who work at several hotels switch.
 * Hidden for users without any hotel (for example platform staff).
 */
export function HotelSwitcher() {
    const { tenancy } = usePage().props;

    if (tenancy.hotels.length === 0) {
        return null;
    }

    const current = tenancy.current;
    const canSwitch = tenancy.hotels.length > 1;

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild disabled={!canSwitch}>
                        <SidebarMenuButton
                            size="lg"
                            tooltip={{ children: current?.name ?? 'Hotel' }}
                            data-testid="hotel-switcher"
                        >
                            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-accent">
                                <Building2 className="size-4" />
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate text-xs text-muted-foreground">
                                    Current hotel
                                </span>
                                <span className="truncate font-medium">
                                    {current?.name ?? 'Select a hotel'}
                                </span>
                            </div>
                            {canSwitch && (
                                <ChevronsUpDown className="ml-auto size-4" />
                            )}
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56"
                        align="start"
                    >
                        <DropdownMenuLabel>Switch hotel</DropdownMenuLabel>
                        {tenancy.hotels.map((hotel) => (
                            <DropdownMenuItem
                                key={hotel.id}
                                onSelect={() =>
                                    router.post(
                                        SwitchHotelController.url(hotel.id),
                                    )
                                }
                            >
                                <span className="flex-1 truncate">
                                    {hotel.name}
                                </span>
                                {hotel.id === current?.id && (
                                    <Check className="size-4" />
                                )}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
