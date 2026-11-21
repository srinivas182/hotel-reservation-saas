import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { t } from '@/lib/i18n';

/**
 * Sprint I: each new screen fades in, and a screen that takes a moment to
 * load shows a calm placeholder instead of looking frozen. Short visits
 * (under 250 ms) show nothing; reduced-motion settings turn the movement off.
 */
export function PageTransition({ children }: { children: ReactNode }) {
    const { component } = usePage();
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        let timer: number | undefined;
        const offStart = router.on('start', (event) => {
            // Polling and partial reloads stay invisible.
            if (
                event.detail.visit.only.length > 0 ||
                event.detail.visit.async
            ) {
                return;
            }
            timer = window.setTimeout(() => setLoading(true), 250);
        });
        const offFinish = router.on('finish', () => {
            window.clearTimeout(timer);
            setLoading(false);
        });

        return () => {
            offStart();
            offFinish();
            window.clearTimeout(timer);
        };
    }, []);

    return (
        <div className="relative flex flex-1 flex-col" aria-busy={loading}>
            {loading && <LoadingPlaceholder />}
            <div
                key={component}
                className={
                    loading
                        ? 'flex flex-1 flex-col opacity-40 transition-opacity duration-200'
                        : 'flex flex-1 animate-enter flex-col'
                }
            >
                {children}
            </div>
        </div>
    );
}

function LoadingPlaceholder() {
    return (
        <div
            className="pointer-events-none absolute inset-x-0 top-0 z-10 space-y-3 p-6"
            role="status"
        >
            <span className="sr-only">{t('Loading…')}</span>
            <div className="h-7 w-56 animate-pulse rounded-lg bg-muted" />
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                {[0, 1, 2, 3].map((i) => (
                    <div
                        key={i}
                        className="h-24 animate-pulse rounded-2xl bg-muted"
                    />
                ))}
            </div>
            <div className="h-64 animate-pulse rounded-2xl bg-muted" />
        </div>
    );
}
