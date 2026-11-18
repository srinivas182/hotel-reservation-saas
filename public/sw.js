/*
 * Staff app service worker (Sprint D).
 *
 * - Built assets: from the phone first (they never change for a release).
 * - Staff pages (/hotel/...): from the network; the last good copy is
 *   kept on the phone and shown only when there is no connection, with the
 *   time it was saved. Copies are deleted at logout.
 * - Notifications: shown with their buttons; a tap on Accept or Undo is
 *   sent to /push/act with the signed code; opening one is reported to
 *   /push/opened, which stops the WhatsApp backup.
 * - The number on the app icon follows the notifications.
 */

const VERSION = 'v1';
const SHELL = `shell-${VERSION}`;
const PAGES = `pages-${VERSION}`;

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(SHELL);
            try {
                const manifest = await (
                    await fetch('/build/manifest.json', { cache: 'no-store' })
                ).json();
                // Only what the app needs to start: entries and their CSS.
                const entries = Object.values(manifest)
                    .filter((entry) => entry.isEntry)
                    .flatMap((entry) => [
                        `/build/${entry.file}`,
                        ...(entry.css ?? []).map((css) => `/build/${css}`),
                    ]);
                await cache.addAll([...new Set(entries)]);
            } catch {
                // No build manifest (development): assets are cached as they are used.
            }
            await self.skipWaiting();
        })(),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            for (const key of await caches.keys()) {
                if (key !== SHELL && key !== PAGES) await caches.delete(key);
            }
            await self.clients.claim();
        })(),
    );
});

function isStaffPage(url) {
    return (
        url.pathname.startsWith('/hotel') && !url.pathname.includes('/media/')
    );
}

async function fromNetworkFirst(request, cacheKey, client) {
    const cache = await caches.open(PAGES);

    // The saved copy only when there is no connection at all: a slow answer is still the fresh one.
    try {
        const response = await fetch(request);
        if (response.ok && response.status === 200 && !response.redirected) {
            const copy = response.clone();
            const headers = new Headers(copy.headers);
            headers.set('X-Saved-At', new Date().toISOString());
            await cache.put(
                cacheKey,
                new Response(await copy.blob(), { status: 200, headers }),
            );
        }
        return response;
    } catch (error) {
        const saved = await cache.match(cacheKey);
        if (saved) {
            notify(client, {
                type: 'offline-page',
                savedAt: saved.headers.get('X-Saved-At'),
            });
            return saved;
        }
        if (request.mode === 'navigate') {
            return offlinePage();
        }
        throw error;
    }
}

async function notify(clientId, message) {
    const client = clientId ? await self.clients.get(clientId) : null;
    const targets = client
        ? [client]
        : await self.clients.matchAll({ type: 'window' });
    for (const target of targets) target.postMessage(message);
}

function offlinePage() {
    const html = `<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hors ligne</title><body style="font-family:system-ui,sans-serif;padding:2rem;max-width:32rem;margin:auto;color:#111">
<h1 style="font-size:1.25rem">Pas de connexion</h1>
<p>Cette page n’a pas encore été enregistrée sur ce téléphone. Elle s’ouvrira dès que la connexion reviendra.</p>
<p lang="en" style="color:#555">No connection. This page has not been saved on this phone yet.</p>
<button onclick="location.reload()" style="padding:.75rem 1rem;font-size:1rem">Réessayer · Retry</button></body></html>`;
    return new Response(html, {
        status: 503,
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
    });
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            (async () => {
                const cached = await caches.match(request);
                if (cached) return cached;
                const response = await fetch(request);
                if (response.ok)
                    (await caches.open(SHELL)).put(request, response.clone());
                return response;
            })(),
        );
        return;
    }

    if (!isStaffPage(url)) return;

    const inertia = request.headers.get('X-Inertia') === 'true';
    if (request.mode === 'navigate' || inertia) {
        // Inertia answers (JSON) and full pages are kept apart.
        const key = `${url.pathname}${url.search}${inertia ? '#inertia' : ''}`;
        event.respondWith(
            fromNetworkFirst(
                request,
                key,
                event.clientId || event.resultingClientId,
            ),
        );
    }
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'logout') {
        event.waitUntil(caches.delete(PAGES));
    }
    if (event.data?.type === 'badge' && 'setAppBadge' in self.navigator) {
        const count = Number(event.data.count) || 0;
        event.waitUntil(
            count > 0
                ? self.navigator.setAppBadge(count)
                : self.navigator.clearAppBadge(),
        );
    }
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: event.data?.text() ?? '' };
    }

    const tokens = {};
    const actions = (data.actions ?? []).slice(0, 2).map((action) => {
        if (action.token) tokens[action.action] = action.token;
        return { action: action.action, title: action.title };
    });

    event.waitUntil(
        Promise.all([
            self.registration.showNotification(data.title || 'Assistant', {
                body: data.body || '',
                tag: data.tag || 'alert',
                renotify: true,
                requireInteraction: data.urgent === true,
                icon: '/icons/icon-192.png',
                badge: '/icons/badge-72.png',
                actions,
                data: {
                    url: data.url || '/hotel/today',
                    hotel: data.hotel,
                    alert: data.alert,
                    opened: data.opened,
                    tokens,
                },
            }),
            'setAppBadge' in self.navigator && typeof data.badge === 'number'
                ? data.badge > 0
                    ? self.navigator.setAppBadge(data.badge)
                    : self.navigator.clearAppBadge()
                : Promise.resolve(),
        ]),
    );
});

async function reportOpened(data) {
    if (!data?.alert || !data?.opened) return;
    try {
        await fetch('/push/opened', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                hotel: data.hotel,
                alert: data.alert,
                signature: data.opened,
            }),
        });
    } catch {
        // Offline: the WhatsApp backup will follow, which is the safe side.
    }
}

async function act(data, token) {
    try {
        const response = await fetch('/push/act', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ hotel: data.hotel, token }),
        });
        const { replies = [] } = await response.json();
        const reply = replies[replies.length - 1];
        if (!reply) return;

        const tokens = {};
        const actions = (reply.buttons ?? [])
            .slice(0, 2)
            .map((button, index) => {
                tokens[`act${index}`] = button.id;
                return { action: `act${index}`, title: button.title };
            });
        await self.registration.showNotification(reply.text.split('\n')[0], {
            body: reply.text.split('\n').slice(1).join('\n'),
            tag: `reply:${data.alert ?? ''}`,
            icon: '/icons/icon-192.png',
            badge: '/icons/badge-72.png',
            actions,
            data: { url: data.url, hotel: data.hotel, tokens },
        });
    } catch {
        await self.registration.showNotification('⚠️', {
            body: 'Pas de connexion : ouvrez l’application pour répondre. · No connection: open the app to answer.',
            tag: `reply:${data.alert ?? ''}`,
            data: { url: data.url, hotel: data.hotel, tokens: {} },
        });
    }
}

self.addEventListener('notificationclick', (event) => {
    const notification = event.notification;
    const data = notification.data || {};
    notification.close();

    const token = event.action ? data.tokens?.[event.action] : undefined;

    event.waitUntil(
        (async () => {
            await reportOpened(data);

            if (token) {
                await act(data, token);
                return;
            }

            const target = new URL(
                data.url || '/hotel/today',
                self.location.origin,
            ).href;
            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const open =
                windows.find((client) => client.url === target) ?? windows[0];
            if (open) {
                await open.focus();
                if (open.url !== target && 'navigate' in open)
                    await open.navigate(target);
                return;
            }
            await self.clients.openWindow(target);
        })(),
    );
});
