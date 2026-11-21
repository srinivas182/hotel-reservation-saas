import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { startPwa } from '@/lib/pwa';

// The brand name is set in Platform → Website (S17); the page carries it.
const appName =
    document.querySelector<HTMLMetaElement>('meta[name="application-name"]')
        ?.content ||
    import.meta.env.VITE_APP_NAME ||
    '';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/') || name.startsWith('invitations/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        // Sprint I: the progress bar takes the hotel's (or platform's) colour.
        color: 'var(--brand)',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// Sprint D: the staff app (offline pages, notifications, install).
startPwa();
