import { router, usePage } from '@inertiajs/react';
import { t } from '@/lib/i18n';
import { Button } from '@/components/ui/button';

type SupportSession = {
    id: string;
    mode: string;
    expires_at: string | null;
    extended: boolean;
    hotel: string | null;
} | null;
type Announcement = {
    id: number;
    title: string;
    body: string;
    level: string;
    dismissible: boolean;
};

/**
 * The support-session banner (platform user inside a hotel) and the
 * platform's announcements, at the top of every hotel page.
 */
export function PlatformBanners() {
    const props = usePage().props as {
        supportSession?: SupportSession;
        announcements?: Announcement[];
    };
    const session = props.supportSession ?? null;
    const announcements = props.announcements ?? [];

    return (
        <>
            {session && (
                <div
                    role="alert"
                    className="mx-4 mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-red-600 px-3 py-2 text-sm text-white"
                >
                    <strong>{t('Support session')}</strong>
                    <span>
                        {session.hotel} ·{' '}
                        {session.mode === 'write'
                            ? t('can make changes')
                            : t('read-only')}{' '}
                        ·{' '}
                        {t('until :time', {
                            time: session.expires_at
                                ? new Date(
                                      session.expires_at,
                                  ).toLocaleTimeString()
                                : '',
                        })}
                    </span>
                    <span className="ml-auto flex gap-2">
                        {!session.extended && (
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() =>
                                    router.post(
                                        '/support/extend',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('Extend 30 minutes')}
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => router.post('/support/end')}
                        >
                            {t('End session')}
                        </Button>
                    </span>
                </div>
            )}
            {announcements.map((a) => (
                <div
                    key={a.id}
                    role="status"
                    className={
                        a.level === 'maintenance'
                            ? 'mx-4 mt-2 rounded-lg border border-amber-500 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-50'
                            : a.level === 'warning'
                              ? 'mx-4 mt-2 rounded-lg border border-orange-400 px-3 py-2 text-sm'
                              : 'mx-4 mt-2 rounded-lg border px-3 py-2 text-sm'
                    }
                >
                    <div className="flex items-start gap-2">
                        <div className="flex-1">
                            <strong>{a.title}</strong>{' '}
                            <span className="whitespace-pre-wrap">
                                {a.body}
                            </span>
                        </div>
                        {a.dismissible && (
                            <button
                                type="button"
                                aria-label={t('Hide')}
                                className="text-muted-foreground"
                                onClick={() =>
                                    router.post(
                                        `/hotel/announcements/${a.id}/dismiss`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                ×
                            </button>
                        )}
                    </div>
                </div>
            ))}
        </>
    );
}
