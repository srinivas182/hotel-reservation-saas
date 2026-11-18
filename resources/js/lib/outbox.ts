import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import InboxController from '@/actions/App/Domains/Conversations/Http/Controllers/InboxController';
import { t } from '@/lib/i18n';

/**
 * Replies written without a connection (Sprint D): kept on the phone and
 * sent, in order, when the connection returns. Only guest replies: other
 * forms keep what was typed and ask to try again, because replaying a
 * booking change later could do the wrong thing.
 */

type Held = { conversation: string; body: string; at: string };

const KEY = 'app.outbox.replies';
let flushing = false;

function read(): Held[] {
    try {
        const raw = window.localStorage.getItem(KEY);

        return raw ? (JSON.parse(raw) as Held[]) : [];
    } catch {
        return [];
    }
}

function write(items: Held[]): boolean {
    try {
        window.localStorage.setItem(KEY, JSON.stringify(items));

        return true;
    } catch {
        return false;
    }
}

export function isOffline(): boolean {
    return typeof navigator !== 'undefined' && navigator.onLine === false;
}

/** Keeps a reply to send later; false if the phone cannot keep it. */
export function holdReply(conversation: string, body: string): boolean {
    return write([
        ...read(),
        { conversation, body, at: new Date().toISOString() },
    ]);
}

export function heldFor(conversation: string): Held[] {
    return read().filter((item) => item.conversation === conversation);
}

export async function flushOutbox(): Promise<void> {
    if (flushing || isOffline()) {
        return;
    }

    flushing = true;

    try {
        let items = read();

        while (items.length > 0 && !isOffline()) {
            const [next, ...rest] = items;
            const sent = await new Promise<boolean>((resolve) => {
                router.post(
                    InboxController.reply.url(next.conversation),
                    { body: next.body },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        onSuccess: () => resolve(true),
                        onError: () => resolve(true), // refused (window closed…): shown, not retried forever
                        onNetworkError: () => {
                            resolve(false);

                            return false;
                        },
                    },
                );
            });

            if (!sent) {
                break;
            }

            items = rest;
            write(items);
        }

        if (items.length === 0 && read().length === 0) {
            write([]);
        }
    } finally {
        flushing = false;
    }

    if (read().length === 0) {
        return;
    }

    toast.message(
        t(':count reply(ies) waiting for the connection.', {
            count: read().length,
        }),
    );
}
