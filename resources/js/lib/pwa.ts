/**
 * The staff app on the phone (Sprint D): service worker, app
 * notifications, the install prompt and the icon badge.
 */

type InstallEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

let installEvent: InstallEvent | null = null;
const installListeners = new Set<() => void>();

const SESSIONS_KEY = 'app.sessions';
const SESSION_FLAG = 'app.session.counted';

function storage(kind: 'local' | 'session'): Storage | null {
    try {
        return kind === 'local' ? window.localStorage : window.sessionStorage;
    } catch {
        return null; // blocked: features that need it simply stay off
    }
}

export function pwaSupported(): boolean {
    return typeof window !== 'undefined' && 'serviceWorker' in navigator;
}

export function pushSupported(): boolean {
    return (
        pwaSupported() && 'PushManager' in window && 'Notification' in window
    );
}

export function isStandalone(): boolean {
    return (
        window.matchMedia?.('(display-mode: standalone)').matches === true ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

export function isIos(): boolean {
    return /iphone|ipad|ipod/i.test(navigator.userAgent);
}

export function deviceLabel(): string {
    const ua = navigator.userAgent;
    const os = /android/i.test(ua)
        ? 'Android'
        : isIos()
          ? 'iPhone'
          : /windows/i.test(ua)
            ? 'Windows'
            : /mac os/i.test(ua)
              ? 'Mac'
              : /linux/i.test(ua)
                ? 'Linux'
                : '';
    const browser = /edg\//i.test(ua)
        ? 'Edge'
        : /firefox/i.test(ua)
          ? 'Firefox'
          : /chrome|crios/i.test(ua)
            ? 'Chrome'
            : /safari/i.test(ua)
              ? 'Safari'
              : '';

    return [os, browser].filter(Boolean).join(' · ').slice(0, 80);
}

/** Visits of this browser, counted once per browser session. */
export function visits(): number {
    const local = storage('local');
    const session = storage('session');

    if (!local || !session) {
        return 0;
    }

    let count = Number(local.getItem(SESSIONS_KEY) ?? '0') || 0;

    if (session.getItem(SESSION_FLAG) !== '1') {
        count += 1;
        local.setItem(SESSIONS_KEY, String(count));
        session.setItem(SESSION_FLAG, '1');
    }

    return count;
}

export function canPromptInstall(): boolean {
    return installEvent !== null;
}

export function onInstallAvailable(listener: () => void): () => void {
    installListeners.add(listener);

    return () => installListeners.delete(listener);
}

export async function promptInstall(): Promise<boolean> {
    if (!installEvent) {
        return false;
    }

    const event = installEvent;
    installEvent = null;
    await event.prompt();

    return (await event.userChoice).outcome === 'accepted';
}

/** Registers the service worker and listens for the install offer. */
export function startPwa(): void {
    if (!pwaSupported()) {
        return;
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installEvent = event as InstallEvent;
        installListeners.forEach((listener) => listener());
    });

    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .catch(() => undefined);
    });
}

function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

function csrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function call(
    method: 'POST' | 'DELETE',
    url: string,
    body: unknown,
): Promise<Response> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
}

export type PushState =
    | 'unsupported'
    | 'ios-install-first'
    | 'denied'
    | 'off'
    | 'on';

export async function pushState(): Promise<PushState> {
    if (!pushSupported()) {
        return isIos() && !isStandalone() ? 'ios-install-first' : 'unsupported';
    }

    if (Notification.permission === 'denied') {
        return 'denied';
    }

    const registration = await navigator.serviceWorker.getRegistration('/');
    const subscription = await registration?.pushManager.getSubscription();

    return subscription && Notification.permission === 'granted' ? 'on' : 'off';
}

/** Asks for permission, subscribes this phone and tells the server. */
export async function turnPushOn(publicKey: string): Promise<PushState> {
    if (!pushSupported()) {
        return pushState();
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return permission === 'denied' ? 'denied' : 'off';
    }

    const registration =
        (await navigator.serviceWorker.getRegistration('/')) ??
        (await navigator.serviceWorker.register('/sw.js', { scope: '/' }));
    await navigator.serviceWorker.ready;

    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(publicKey),
        }));

    const json = subscription.toJSON();
    const response = await call('POST', '/hotel/push/subscriptions', {
        endpoint: json.endpoint,
        keys: json.keys,
        device: deviceLabel(),
    });

    if (!response.ok) {
        await subscription.unsubscribe();

        return 'off';
    }

    return 'on';
}

export async function turnPushOff(): Promise<void> {
    const registration = await navigator.serviceWorker?.getRegistration('/');
    const subscription = await registration?.pushManager.getSubscription();

    if (subscription) {
        await call('DELETE', '/hotel/push/subscriptions', {
            endpoint: subscription.endpoint,
        }).catch(() => undefined);
        await subscription.unsubscribe();
    }
}

export async function sendTestPush(): Promise<number> {
    const response = await call('POST', '/hotel/push/test', {});

    if (!response.ok) {
        return 0;
    }

    return Number(((await response.json()) as { sent?: number }).sent ?? 0);
}

/**
 * At logout: this phone stops receiving the person's notifications, and
 * the pages saved for offline use are deleted.
 */
export async function forgetOnLogout(): Promise<void> {
    if (!pwaSupported()) {
        return;
    }

    navigator.serviceWorker.controller?.postMessage({ type: 'logout' });
    await turnPushOff().catch(() => undefined);
}

/** The number on the app icon. */
export function setBadge(count: number): void {
    const nav = navigator as Navigator & {
        setAppBadge?: (n: number) => Promise<void>;
        clearAppBadge?: () => Promise<void>;
    };

    if (!nav.setAppBadge || !nav.clearAppBadge) {
        return;
    }

    void (count > 0 ? nav.setAppBadge(count) : nav.clearAppBadge()).catch(
        () => undefined,
    );
}
