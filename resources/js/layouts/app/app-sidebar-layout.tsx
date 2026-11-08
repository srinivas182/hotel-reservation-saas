import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { PlatformBanners } from '@/components/platform-banners';
import { t } from '@/lib/i18n';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const billingReduced =
        (usePage().props as { billingReduced?: boolean }).billingReduced ===
        true;
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <PlatformBanners />
                {billingReduced && (
                    <p
                        role="alert"
                        className="mx-4 mt-2 rounded-lg border border-destructive px-3 py-2 text-sm text-destructive"
                    >
                        {t(
                            'An invoice is unpaid: the assistant is paused and settings are read-only. Day-to-day work goes on. See Billing.',
                        )}
                    </p>
                )}
                {children}
            </AppContent>
        </AppShell>
    );
}
