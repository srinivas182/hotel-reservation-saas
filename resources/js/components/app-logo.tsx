import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name, brandLogo } = usePage().props as {
        name: string;
        brandLogo?: string | null;
    };

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-lg bg-sidebar-primary text-sidebar-primary-foreground shadow-[0_6px_16px_-6px_var(--sidebar-primary)]">
                {brandLogo ? (
                    <img
                        src={brandLogo}
                        alt=""
                        className="size-8 object-contain"
                    />
                ) : (
                    <AppLogoIcon className="size-5 fill-current" />
                )}
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate font-display text-[15px] leading-tight font-bold tracking-tight text-sidebar-accent-foreground">
                    {name}
                </span>
            </div>
        </>
    );
}
