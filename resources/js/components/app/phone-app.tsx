import { router, usePage } from '@inertiajs/react';
import { Download, WifiOff, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { flushOutbox } from '@/lib/outbox';
import {
    canPromptInstall,
    isIos,
    isStandalone,
    onInstallAvailable,
    promptInstall,
    setBadge,
    visits,
} from '@/lib/pwa';

const DISMISS_KEY = 'app.install.dismissed';
const DISMISS_DAYS = 30;

function dismissedRecently(): boolean {
    try {
        const at = Number(window.localStorage.getItem(DISMISS_KEY) ?? '0');

        return Date.now() - at < DISMISS_DAYS * 86_400_000;
    } catch {
        return true;
    }
}

/**
 * Addition 2: the install offer, at the second visit on this phone (not
 * the first), never inside the installed app, and not again for 30 days
 * once dismissed. On iPhone, how to add it to the home screen.
 */
function InstallPrompt() {
    const [show, setShow] = useState(false);
    const [ios, setIos] = useState(false);

    useEffect(() => {
        if (isStandalone() || dismissedRecently() || visits() < 2) {
            return;
        }

        const update = () => {
            const iosNeeded = isIos();
            setIos(iosNeeded && !canPromptInstall());
            setShow(canPromptInstall() || iosNeeded);
        };
        update();

        return onInstallAvailable(update);
    }, []);

    if (!show) {
        return null;
    }

    const dismiss = () => {
        setShow(false);
        try {
            window.localStorage.setItem(DISMISS_KEY, String(Date.now()));
        } catch {
            // not remembered: it may come back next visit
        }
    };

    return (
        <div
            role="region"
            aria-label={t('Install the app')}
            className="mx-4 mt-2 flex items-start gap-3 rounded-lg border border-primary/40 bg-card px-3 py-2 text-sm"
        >
            <Download className="mt-0.5 size-5 shrink-0" aria-hidden />
            <div className="flex flex-1 flex-col gap-1">
                <p className="font-medium">
                    {t('Put the app on your home screen')}
                </p>
                <p className="text-xs text-muted-foreground">
                    {ios
                        ? t(
                              'In Safari, tap Share, then “Add to Home Screen”. Notifications on iPhone need this.',
                          )
                        : t(
                              'It opens in one tap, works on a weak connection and shows notifications for new requests.',
                          )}
                </p>
                {!ios && (
                    <Button
                        size="sm"
                        className="w-fit"
                        onClick={() => {
                            void promptInstall().then(() => setShow(false));
                        }}
                    >
                        {t('Install')}
                    </Button>
                )}
            </div>
            <button
                type="button"
                onClick={dismiss}
                aria-label={t('Not now')}
                className="rounded p-1 hover:bg-muted"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}

/**
 * Weak connections: says when the phone is offline, and when a page is the
 * copy saved on the phone (with its time).
 */
function ConnectionBanner() {
    const [online, setOnline] = useState(true);
    const [savedAt, setSavedAt] = useState<string | null>(null);

    useEffect(() => {
        setOnline(navigator.onLine);
        const up = () => {
            setOnline(true);
            setSavedAt(null);
            void flushOutbox();
        };
        const down = () => setOnline(false);
        const fromWorker = (event: MessageEvent) => {
            const data = event.data as { type?: string; savedAt?: string };

            if (data?.type === 'offline-page') {
                setSavedAt(data.savedAt ?? null);
            }
        };
        window.addEventListener('online', up);
        window.addEventListener('offline', down);
        navigator.serviceWorker?.addEventListener('message', fromWorker);
        const off = router.on('success', () => {
            if (navigator.onLine) {
                setSavedAt(null);
            }
        });

        if (navigator.onLine) {
            void flushOutbox();
        }

        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
            navigator.serviceWorker?.removeEventListener('message', fromWorker);
            off();
        };
    }, []);

    if (online && savedAt === null) {
        return null;
    }

    const time = savedAt
        ? new Date(savedAt).toLocaleTimeString([], {
              hour: '2-digit',
              minute: '2-digit',
          })
        : null;

    return (
        <p
            role="status"
            className="mx-4 mt-2 flex items-center gap-2 rounded-lg border border-amber-500 bg-card px-3 py-2 text-sm"
        >
            <WifiOff className="size-4 shrink-0" aria-hidden />
            {time
                ? t(
                      'No connection: this is the page saved at :time. Replies you write are kept and sent when the connection returns.',
                      { time },
                  )
                : t(
                      'No connection. Replies you write are kept and sent when the connection returns.',
                  )}
        </p>
    );
}

/** Addition 6: the number on the app icon follows each page. */
function IconBadge() {
    const count = (usePage().props as { appBadge?: number }).appBadge ?? 0;

    useEffect(() => {
        setBadge(count);
        navigator.serviceWorker?.controller?.postMessage({
            type: 'badge',
            count,
        });
    }, [count]);

    return null;
}

/** Everything the staff app adds to the dashboard layout. */
export function PhoneApp() {
    return (
        <>
            <ConnectionBanner />
            <InstallPrompt />
            <IconBadge />
        </>
    );
}
