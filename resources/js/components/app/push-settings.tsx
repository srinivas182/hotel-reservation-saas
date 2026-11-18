import { usePage } from '@inertiajs/react';
import { BellRing } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import {
    isIos,
    pushState,
    sendTestPush,
    turnPushOff,
    turnPushOn,
} from '@/lib/pwa';
import type { PushState } from '@/lib/pwa';

export type Device = {
    id: number;
    device: string | null;
    since: string | null;
    last: string | null;
};

/** Plain help when notifications do not arrive (addition 3). */
function Help({ state }: { state: PushState }) {
    if (state === 'ios-install-first') {
        return (
            <p className="text-xs text-muted-foreground">
                {t(
                    'On iPhone, notifications need the app on the home screen (iOS 16.4 or later): in Safari, tap Share, then “Add to Home Screen”, open it from there and come back here.',
                )}
            </p>
        );
    }

    return (
        <ul className="list-disc pl-5 text-xs text-muted-foreground">
            {state === 'denied' && (
                <li>
                    {t(
                        'Notifications are blocked for this site. Tap the lock next to the address, then Permissions → Notifications → Allow.',
                    )}
                </li>
            )}
            {!isIos() && (
                <li>
                    {t(
                        'Android: battery saver can hold notifications back. Settings → Apps → Chrome → Battery → Unrestricted.',
                    )}
                </li>
            )}
            <li>
                {t(
                    'Check that the phone is not in “Do not disturb” and that Chrome notifications are on in the phone settings.',
                )}
            </li>
            <li>
                {t(
                    'If a notification is not opened within 5 minutes, the WhatsApp alert follows, when you have a WhatsApp number above.',
                )}
            </li>
        </ul>
    );
}

export function PushSettings({ devices }: { devices: Device[] }) {
    const push = (usePage().props as { push?: { key: string } | null }).push;
    const [state, setState] = useState<PushState | null>(null);
    const [busy, setBusy] = useState(false);
    const [test, setTest] = useState<'sent' | 'failed' | null>(null);

    useEffect(() => {
        void pushState().then(setState);
    }, []);

    if (!push) {
        return (
            <p className="text-xs text-muted-foreground">
                {t(
                    'App notifications are not set up on this server yet. Alerts come by WhatsApp.',
                )}
            </p>
        );
    }

    const run = async (work: () => Promise<void>) => {
        setBusy(true);
        setTest(null);
        try {
            await work();
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="flex flex-col gap-2 text-sm">
            <p className="flex items-center gap-2 font-medium">
                <BellRing className="size-4" aria-hidden />
                {t('Notifications on this phone')}
                <span
                    className={
                        state === 'on'
                            ? 'rounded bg-emerald-100 px-1.5 text-xs text-emerald-900 dark:bg-emerald-900 dark:text-emerald-100'
                            : 'rounded bg-muted px-1.5 text-xs'
                    }
                >
                    {state === 'on' ? t('On') : t('Off')}
                </span>
            </p>
            <p className="text-xs text-muted-foreground">
                {t(
                    'Free, on the lock screen, with Accept right on a new request. The alerts you ticked above come here first.',
                )}
            </p>
            <div className="flex flex-wrap gap-2">
                {state !== 'on' ? (
                    <Button
                        type="button"
                        size="sm"
                        disabled={
                            busy ||
                            state === 'unsupported' ||
                            state === 'ios-install-first'
                        }
                        onClick={() =>
                            run(async () =>
                                setState(await turnPushOn(push.key)),
                            )
                        }
                    >
                        {busy && <Spinner />}
                        {t('Turn on notifications on this phone')}
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            size="sm"
                            disabled={busy}
                            onClick={() =>
                                run(async () =>
                                    setTest(
                                        (await sendTestPush()) > 0
                                            ? 'sent'
                                            : 'failed',
                                    ),
                                )
                            }
                        >
                            {busy && <Spinner />}
                            {t('Send a test notification')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            disabled={busy}
                            onClick={() =>
                                run(async () => {
                                    await turnPushOff();
                                    setState(await pushState());
                                })
                            }
                        >
                            {t('Turn off on this phone')}
                        </Button>
                    </>
                )}
            </div>
            {state === 'unsupported' && (
                <p className="text-xs text-muted-foreground">
                    {t(
                        'This browser cannot show notifications. Use Chrome on Android, or the app on the home screen on iPhone.',
                    )}
                </p>
            )}
            {test === 'sent' && (
                <p role="status" className="text-xs">
                    {t(
                        'Sent. If nothing appears within a minute, see the help below.',
                    )}
                </p>
            )}
            {test === 'failed' && (
                <p role="alert" className="text-xs text-destructive">
                    {t(
                        'The test could not be sent to this phone. Turn notifications off and on again, then try the help below.',
                    )}
                </p>
            )}
            {(state === 'denied' ||
                state === 'ios-install-first' ||
                test !== null) && <Help state={state ?? 'off'} />}
            {devices.length > 0 && (
                <div className="text-xs text-muted-foreground">
                    <p>{t('Your phones with notifications:')}</p>
                    <ul className="list-disc pl-5">
                        {devices.map((d) => (
                            <li key={d.id}>
                                {d.device ?? t('Unknown device')}
                                {d.last &&
                                    ' · ' +
                                        t('last notification :date', {
                                            date: new Date(
                                                d.last,
                                            ).toLocaleString(),
                                        })}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
