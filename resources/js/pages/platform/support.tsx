import { Head, Link, router, useForm } from '@inertiajs/react';
import PlatformSupportController from '@/actions/App/Domains/Support/Http/Controllers/PlatformSupportController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Ticket = {
    id: number;
    subject: string;
    category: string;
    status: string;
    priority: string;
    late: boolean;
    rating: string | null;
    hotel: string | null;
    assignee: string | null;
    due: string | null;
    updated: string | null;
};
type Reply = { id: number; title: string; body: { fr: string; en: string } };
type Props = {
    filter: string;
    tickets: Ticket[];
    counts: { open: number; late: number; mine: number };
    satisfaction: { good: number; bad: number };
    settings: {
        response_hours: Record<string, number>;
        ai_drafts: boolean;
        whatsapp_hotel_id: string | null;
        whatsapp_template: string;
        whatsapp_ready: boolean;
    };
    hotels: { id: string; name: string }[];
    savedReplies: Reply[];
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function SettingsForm({
    settings,
    hotels,
}: {
    settings: Props['settings'];
    hotels: Props['hotels'];
}) {
    const form = useForm({
        response_hours: Object.entries(settings.response_hours)
            .map(([k, v]) => `${k}=${v}`)
            .join(', '),
        ai_drafts: settings.ai_drafts,
        whatsapp_hotel_id: settings.whatsapp_hotel_id ?? '',
        whatsapp_template: settings.whatsapp_template,
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    whatsapp_hotel_id: d.whatsapp_hotel_id || null,
                    response_hours: Object.fromEntries(
                        d.response_hours
                            .split(',')
                            .map((p) => p.split('=').map((x) => x.trim()))
                            .filter((p) => p.length === 2 && p[0] !== '')
                            .map(([k, v]) => [k, Number(v)]),
                    ),
                }));
                form.put(PlatformSupportController.settings.url(), {
                    preserveScroll: true,
                });
            }}
            className="grid gap-2 rounded-xl border p-3 sm:grid-cols-2"
        >
            <label className="grid gap-0.5 text-xs">
                {t(
                    'First answer within (hours) by plan, e.g. default=24, pro=8',
                )}
                <Input
                    value={form.data.response_hours}
                    onChange={(e) =>
                        form.setData('response_hours', e.target.value)
                    }
                />
            </label>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={form.data.ai_drafts}
                    onChange={(e) =>
                        form.setData('ai_drafts', e.target.checked)
                    }
                />
                {t(
                    'AI drafts for replies (paid by the platform; always reviewed)',
                )}
            </label>
            <label className="grid gap-0.5 text-xs">
                {t(
                    'WhatsApp number for support notices (an account connected like a hotel’s)',
                )}
                <select
                    className={selectClass}
                    value={form.data.whatsapp_hotel_id}
                    onChange={(e) =>
                        form.setData('whatsapp_hotel_id', e.target.value)
                    }
                >
                    <option value="">{t('Not set: email only')}</option>
                    {hotels.map((h) => (
                        <option key={h.id} value={h.id}>
                            {h.name}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('Approved template name')}
                <Input
                    value={form.data.whatsapp_template}
                    onChange={(e) =>
                        form.setData('whatsapp_template', e.target.value)
                    }
                />
            </label>
            <p className="text-xs text-muted-foreground sm:col-span-2">
                {settings.whatsapp_ready
                    ? t('WhatsApp notices are ready.')
                    : t(
                          'WhatsApp notices are off until the number and an approved template are set.',
                      )}
            </p>
            <div>
                <Button size="sm" disabled={form.processing}>
                    {t('Save')}
                </Button>
            </div>
        </form>
    );
}

function ReplyForm({ reply }: { reply?: Reply }) {
    const form = useForm({
        title: reply?.title ?? '',
        body: reply?.body ?? { fr: '', en: '' },
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (reply) {
                    form.put(
                        PlatformSupportController.updateReply.url(reply.id),
                        { preserveScroll: true },
                    );
                } else {
                    form.post(PlatformSupportController.storeReply.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }
            }}
            className="grid gap-2 p-2 sm:grid-cols-2"
        >
            <Input
                aria-label={t('Title')}
                placeholder={t('Title')}
                className="sm:col-span-2"
                value={form.data.title}
                onChange={(e) => form.setData('title', e.target.value)}
            />
            {(['fr', 'en'] as const).map((l) => (
                <textarea
                    key={l}
                    aria-label={`${t('Text')} ${l}`}
                    placeholder={`${t('Text')} (${l}) — {name}, {hotel}, {agent}`}
                    className="min-h-28 rounded-md border border-input bg-transparent p-2 text-xs"
                    value={form.data.body[l]}
                    onChange={(e) =>
                        form.setData('body', {
                            ...form.data.body,
                            [l]: e.target.value,
                        })
                    }
                />
            ))}
            <div className="flex gap-2">
                <Button size="sm" disabled={form.processing}>
                    {reply ? t('Save') : t('Add saved reply')}
                </Button>
                {reply && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            router.delete(
                                PlatformSupportController.destroyReply.url(
                                    reply.id,
                                ),
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('Delete')}
                    </Button>
                )}
            </div>
        </form>
    );
}

export default function PlatformSupport({
    filter,
    tickets,
    counts,
    satisfaction,
    settings,
    hotels,
    savedReplies,
}: Props) {
    const total = satisfaction.good + satisfaction.bad;

    return (
        <>
            <Head title={t('Support requests')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('Support requests')}
                    description={t(
                        'Requests from hotels, first-answer targets, satisfaction, saved replies.',
                    )}
                />
                <div className="flex flex-wrap items-center gap-2">
                    {(
                        [
                            ['open', t('In progress'), counts.open],
                            ['late', t('Late'), counts.late],
                            ['mine', t('Mine'), counts.mine],
                            ['solved', t('Solved'), null],
                        ] as const
                    ).map(([key, label, n]) => (
                        <Button
                            key={key}
                            size="sm"
                            variant={filter === key ? 'default' : 'outline'}
                            onClick={() =>
                                router.get(
                                    PlatformSupportController.index.url({
                                        query: { filter: key },
                                    }),
                                )
                            }
                        >
                            {label} {n !== null ? `(${n})` : ''}
                        </Button>
                    ))}
                    <span className="ml-auto text-muted-foreground">
                        {total > 0
                            ? t('Satisfaction (90 days): :pct% (:n ratings)', {
                                  pct: Math.round(
                                      (100 * satisfaction.good) / total,
                                  ),
                                  n: total,
                              })
                            : t('No ratings yet.')}
                    </span>
                </div>
                <ul className="divide-y rounded-xl border">
                    {tickets.length === 0 && (
                        <li className="px-3 py-2 text-muted-foreground">
                            {t('No requests here.')}
                        </li>
                    )}
                    {tickets.map((tk) => (
                        <li
                            key={tk.id}
                            className="flex flex-wrap items-center gap-2 px-3 py-2"
                        >
                            <Link
                                href={PlatformSupportController.show.url(tk.id)}
                                className="font-medium underline"
                            >
                                #{tk.id} {tk.subject}
                            </Link>
                            <span className="text-muted-foreground">
                                {tk.hotel} · {tk.category}
                            </span>
                            {tk.priority !== 'normal' && (
                                <Badge variant="destructive">
                                    {tk.priority}
                                </Badge>
                            )}
                            {tk.late && (
                                <Badge variant="destructive">{t('Late')}</Badge>
                            )}
                            {tk.rating && (
                                <span aria-label={tk.rating}>
                                    {tk.rating === 'good' ? '👍' : '👎'}
                                </span>
                            )}
                            <span className="ml-auto text-xs text-muted-foreground">
                                {tk.assignee ?? t('Nobody')} ·{' '}
                                {t('due :date', { date: tk.due ?? '—' })}
                            </span>
                        </li>
                    ))}
                </ul>
                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Settings')} />
                    <SettingsForm settings={settings} hotels={hotels} />
                </section>
                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Saved replies')} />
                    {savedReplies.map((r) => (
                        <details key={r.id} className="rounded-xl border p-2">
                            <summary className="cursor-pointer">
                                {r.title}
                            </summary>
                            <ReplyForm reply={r} />
                        </details>
                    ))}
                    <div className="rounded-xl border">
                        <ReplyForm />
                    </div>
                </section>
            </div>
        </>
    );
}

PlatformSupport.layout = {
    breadcrumbs: [
        { title: 'Support requests', href: PlatformSupportController.index() },
    ],
};
