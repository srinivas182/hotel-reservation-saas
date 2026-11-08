import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import PlatformSupportController from '@/actions/App/Domains/Support/Http/Controllers/PlatformSupportController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Thread } from '@/components/support/thread';
import type { ThreadMessage } from '@/components/support/thread';
import { Button } from '@/components/ui/button';
import { postJson } from '@/lib/http';
import { t } from '@/lib/i18n';

type Props = {
    ticket: {
        id: number;
        subject: string;
        category: string;
        status: string;
        priority: string;
        hotel: string | null;
        hotel_id: string;
        assignee_id: string | null;
        due: string | null;
        late: boolean;
        rating: string | null;
        rating_comment: string | null;
        notify_whatsapp: boolean;
        requester: { name: string; email: string; phone: string | null } | null;
        context: Record<string, string> | null;
        messages: ThreadMessage[];
    };
    agents: { id: string; name: string }[];
    savedReplies: { id: number; title: string; text: string }[];
    aiDrafts: boolean;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function PlatformSupportTicket({
    ticket,
    agents,
    savedReplies,
    aiDrafts,
}: Props) {
    const reply = useForm({
        body: '',
        internal: false,
        solve: false,
        ai_drafted: false,
    });
    const meta = useForm({
        assignee_id: ticket.assignee_id ?? '',
        priority: ticket.priority,
        status: ticket.status,
    });
    const [drafting, setDrafting] = useState(false);
    const [draftError, setDraftError] = useState<string | null>(null);

    const draft = () => {
        setDrafting(true);
        setDraftError(null);
        postJson<{ draft: string }>(
            PlatformSupportController.draft.url(ticket.id),
            {},
        )
            .then((r) =>
                reply.setData((d) => ({
                    ...d,
                    body: r.draft,
                    ai_drafted: true,
                    internal: false,
                })),
            )
            .catch(() =>
                setDraftError(
                    t('No AI draft right now. Please write the reply.'),
                ),
            )
            .finally(() => setDrafting(false));
    };

    return (
        <>
            <Head title={`#${ticket.id}`} />
            <div className="grid max-w-6xl gap-6 p-4 text-sm lg:grid-cols-[1fr_300px]">
                <div className="flex flex-col gap-4">
                    <Heading
                        title={`#${ticket.id} ${ticket.subject}`}
                        description={`${ticket.hotel ?? ''} · ${ticket.category}`}
                    />
                    <Thread messages={ticket.messages} />
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            reply.post(
                                PlatformSupportController.reply.url(ticket.id),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => reply.reset(),
                                },
                            );
                        }}
                        className="flex flex-col gap-2 rounded-xl border p-3"
                    >
                        <div className="flex flex-wrap gap-2">
                            <select
                                aria-label={t('Insert a saved reply')}
                                className={`${selectClass} w-auto`}
                                value=""
                                onChange={(e) =>
                                    reply.setData(
                                        'body',
                                        savedReplies.find(
                                            (r) =>
                                                String(r.id) === e.target.value,
                                        )?.text ?? reply.data.body,
                                    )
                                }
                            >
                                <option value="">
                                    {t('Insert a saved reply…')}
                                </option>
                                {savedReplies.map((r) => (
                                    <option key={r.id} value={r.id}>
                                        {r.title}
                                    </option>
                                ))}
                            </select>
                            {aiDrafts && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={draft}
                                    disabled={drafting}
                                >
                                    {drafting
                                        ? t('Drafting…')
                                        : t('Draft with AI')}
                                </Button>
                            )}
                        </div>
                        {draftError && (
                            <p className="text-destructive">{draftError}</p>
                        )}
                        {reply.data.ai_drafted && (
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'AI draft: read and correct it before sending.',
                                )}
                            </p>
                        )}
                        <textarea
                            aria-label={t('Reply')}
                            className="min-h-40 rounded-md border border-input bg-transparent px-3 py-2"
                            value={reply.data.body}
                            onChange={(e) =>
                                reply.setData('body', e.target.value)
                            }
                        />
                        <InputError message={reply.errors.body} />
                        <div className="flex flex-wrap items-center gap-4">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={reply.data.internal}
                                    onChange={(e) =>
                                        reply.setData(
                                            'internal',
                                            e.target.checked,
                                        )
                                    }
                                />
                                {t('Internal note (not sent to the hotel)')}
                            </label>
                            {!reply.data.internal && (
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={reply.data.solve}
                                        onChange={(e) =>
                                            reply.setData(
                                                'solve',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    {t('Send and mark solved')}
                                </label>
                            )}
                            <Button
                                size="sm"
                                className="ml-auto"
                                disabled={
                                    reply.processing ||
                                    reply.data.body.trim() === ''
                                }
                            >
                                {reply.data.internal
                                    ? t('Add note')
                                    : t('Send')}
                            </Button>
                        </div>
                    </form>
                </div>
                <aside className="flex flex-col gap-4">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            meta.transform((d) => ({
                                ...d,
                                assignee_id: d.assignee_id || null,
                            }));
                            meta.put(
                                PlatformSupportController.update.url(ticket.id),
                                { preserveScroll: true },
                            );
                        }}
                        className="flex flex-col gap-2 rounded-xl border p-3"
                    >
                        <label className="grid gap-0.5 text-xs">
                            {t('Assigned to')}
                            <select
                                className={selectClass}
                                value={meta.data.assignee_id}
                                onChange={(e) =>
                                    meta.setData('assignee_id', e.target.value)
                                }
                            >
                                <option value="">{t('Nobody')}</option>
                                {agents.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-0.5 text-xs">
                            {t('Priority')}
                            <select
                                className={selectClass}
                                value={meta.data.priority}
                                onChange={(e) =>
                                    meta.setData('priority', e.target.value)
                                }
                            >
                                <option value="normal">{t('Normal')}</option>
                                <option value="high">{t('High')}</option>
                                <option value="urgent">{t('Urgent')}</option>
                            </select>
                        </label>
                        <label className="grid gap-0.5 text-xs">
                            {t('Status')}
                            <select
                                className={selectClass}
                                value={meta.data.status}
                                onChange={(e) =>
                                    meta.setData('status', e.target.value)
                                }
                            >
                                <option value="open">{t('In progress')}</option>
                                <option value="waiting_hotel">
                                    {t('Waiting for the hotel')}
                                </option>
                                <option value="solved">{t('Solved')}</option>
                            </select>
                        </label>
                        <Button size="sm" disabled={meta.processing}>
                            {t('Save')}
                        </Button>
                    </form>
                    <section className="rounded-xl border p-3">
                        <h3 className="mb-1 font-semibold">{t('Context')}</h3>
                        <p>
                            <Link
                                href={HotelOverviewController.show(
                                    ticket.hotel_id,
                                )}
                                className="underline"
                            >
                                {ticket.hotel}
                            </Link>
                        </p>
                        {ticket.requester && (
                            <p className="text-muted-foreground">
                                {ticket.requester.name} ·{' '}
                                {ticket.requester.email}{' '}
                                {ticket.requester.phone ?? ''}
                            </p>
                        )}
                        <p className="text-muted-foreground">
                            {t('First answer due :date', {
                                date: ticket.due ?? '—',
                            })}
                        </p>
                        {ticket.notify_whatsapp && (
                            <p className="text-muted-foreground">
                                {t('Also notified on WhatsApp')}
                            </p>
                        )}
                        <dl className="mt-2 grid gap-1 text-xs">
                            {Object.entries(ticket.context ?? {})
                                .filter(([, v]) => v)
                                .map(([k, v]) => (
                                    <div key={k}>
                                        <dt className="font-medium">{k}</dt>
                                        <dd className="break-all text-muted-foreground">
                                            {v}
                                        </dd>
                                    </div>
                                ))}
                        </dl>
                        {ticket.rating && (
                            <p className="mt-2">
                                {ticket.rating === 'good' ? '👍' : '👎'}{' '}
                                {ticket.rating_comment}
                            </p>
                        )}
                    </section>
                </aside>
            </div>
        </>
    );
}

PlatformSupportTicket.layout = {
    breadcrumbs: [
        { title: 'Support requests', href: PlatformSupportController.index() },
    ],
};
