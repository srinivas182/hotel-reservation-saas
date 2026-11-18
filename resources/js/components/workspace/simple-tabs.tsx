import { Link, usePage } from '@inertiajs/react';
import { ClipboardList, Inbox, Sun } from 'lucide-react';
import { t } from '@/lib/i18n';
import { useWorkspace } from '@/lib/workspace';

/**
 * Simple mode on a phone: three big tabs at the bottom, Today, Messages,
 * Bookings, each shown only to those who may open it.
 */
export function SimpleTabs() {
    const workspace = useWorkspace();
    const page = usePage();
    const { auth, inboxUnread } = page.props as unknown as {
        auth: { permissions: string[] };
        inboxUnread?: number;
    };

    if (!workspace?.simple) {
        return null;
    }

    const tabs = [
        auth.permissions.includes('bookings.view') && {
            href: '/hotel/today',
            label: t('Today'),
            icon: Sun,
            badge: 0,
            tour: 'tab-today',
        },
        auth.permissions.includes('conversations.view') && {
            href: '/hotel/inbox',
            label: t('Messages'),
            icon: Inbox,
            badge: inboxUnread ?? 0,
            tour: 'tab-inbox',
        },
        auth.permissions.includes('bookings.view') && {
            href: '/hotel/bookings',
            label: t('Bookings'),
            icon: ClipboardList,
            badge: 0,
            tour: 'tab-bookings',
        },
    ].filter((tab) => tab !== false);

    if (tabs.length === 0) {
        return null;
    }

    return (
        <nav
            aria-label={t('Main')}
            className="fixed inset-x-0 bottom-0 z-40 grid border-t bg-card pb-[env(safe-area-inset-bottom)] md:hidden"
            style={{ gridTemplateColumns: `repeat(${tabs.length}, 1fr)` }}
        >
            {tabs.map((tab) => {
                const active = page.url.startsWith(tab.href);
                const Icon = tab.icon;

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        data-tour={tab.tour}
                        aria-current={active ? 'page' : undefined}
                        className={`relative flex min-h-16 flex-col items-center justify-center gap-1 text-sm font-medium ${active ? 'text-primary' : 'text-muted-foreground'}`}
                    >
                        <Icon className="size-6" aria-hidden />
                        {tab.label}
                        {tab.badge > 0 && (
                            <span className="absolute top-2 right-[calc(50%-1.6rem)] min-w-5 rounded-full bg-destructive px-1 text-xs text-white">
                                {tab.badge}
                            </span>
                        )}
                    </Link>
                );
            })}
        </nav>
    );
}
