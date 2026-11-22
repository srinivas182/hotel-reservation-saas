import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import PlatformSupportController from '@/actions/App/Domains/Support/Http/Controllers/PlatformSupportController';
import {
    DataTable,
    Field,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    StatTile,
    Switch,
    Tabs,
    useTabs,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

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
            className="flex flex-col gap-6"
        >
            <Section
                aside
                title={t('Answer times')}
                description={t(
                    'How fast hotels get a first answer, by plan. Requests past this are marked late.',
                )}
            >
                <div className="grid gap-4">
                    <Field
                        label={t('First answer within (hours), by plan')}
                        hint={t('e.g. default=24, pro=8')}
                    >
                        <Input
                            value={form.data.response_hours}
                            onChange={(e) =>
                                form.setData('response_hours', e.target.value)
                            }
                        />
                    </Field>
                    <Switch
                        checked={form.data.ai_drafts}
                        onChange={(v) => form.setData('ai_drafts', v)}
                        label={t('AI drafts for replies')}
                        hint={t(
                            'Paid by the platform; an agent always reviews the draft.',
                        )}
                    />
                </div>
            </Section>
            <Section
                aside
                title={t('WhatsApp notices')}
                description={
                    settings.whatsapp_ready
                        ? t('WhatsApp notices are ready.')
                        : t(
                              'WhatsApp notices are off until the number and an approved template are set.',
                          )
                }
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label={t('WhatsApp number for support notices')}
                        hint={t('An account connected like a hotel’s.')}
                    >
                        <select
                            className="h-9"
                            value={form.data.whatsapp_hotel_id}
                            onChange={(e) =>
                                form.setData(
                                    'whatsapp_hotel_id',
                                    e.target.value,
                                )
                            }
                        >
                            <option value="">{t('Not set: email only')}</option>
                            {hotels.map((h) => (
                                <option key={h.id} value={h.id}>
                                    {h.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label={t('Approved template name')}>
                        <Input
                            value={form.data.whatsapp_template}
                            onChange={(e) =>
                                form.setData(
                                    'whatsapp_template',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                </div>
            </Section>
            <div className="flex justify-end">
                <Button disabled={form.processing}>{t('Save settings')}</Button>
            </div>
        </form>
    );
}

function ReplyForm({ reply, onDone }: { reply?: Reply; onDone: () => void }) {
    const form = useForm({
        title: reply?.title ?? '',
        body: reply?.body ?? { fr: '', en: '' },
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (reply) {
                    form.put(
                        PlatformSupportController.updateReply.url(reply.id),
                        { preserveScroll: true, onSuccess: onDone },
                    );
                } else {
                    form.post(PlatformSupportController.storeReply.url(), {
                        preserveScroll: true,
                        onSuccess: onDone,
                    });
                }
            }}
            className="grid gap-4"
        >
            <Field label={t('Title')} error={errors.title}>
                <Input
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                />
            </Field>
            {(['fr', 'en'] as const).map((l) => (
                <Field
                    key={l}
                    label={
                        l === 'fr' ? t('Text (French)') : t('Text (English)')
                    }
                    hint={t('You can use {name}, {hotel} and {agent}.')}
                    error={errors[`body.${l}`]}
                >
                    <textarea
                        className="min-h-32"
                        value={form.data.body[l]}
                        onChange={(e) =>
                            form.setData('body', {
                                ...form.data.body,
                                [l]: e.target.value,
                            })
                        }
                    />
                </Field>
            ))}
            <div className="flex items-center justify-between gap-2 border-t pt-4">
                {reply ? (
                    <Button
                        type="button"
                        variant="ghost"
                        className="text-destructive"
                        onClick={() =>
                            router.delete(
                                PlatformSupportController.destroyReply.url(
                                    reply.id,
                                ),
                                { preserveScroll: true, onSuccess: onDone },
                            )
                        }
                    >
                        {t('Delete')}
                    </Button>
                ) : (
                    <span />
                )}
                <Button disabled={form.processing}>
                    {reply ? t('Save') : t('Add saved reply')}
                </Button>
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
    const tabs = [
        { key: 'requests', label: t('Requests'), count: counts.open },
        {
            key: 'replies',
            label: t('Saved replies'),
            count: savedReplies.length,
        },
        { key: 'settings', label: t('Settings') },
    ];
    const [tab, setTab] = useTabs(tabs);
    const [reply, setReply] = useState<Reply | 'new' | null>(null);
    const editing = reply === 'new' || reply === null ? undefined : reply;
    const filters = [
        ['open', t('In progress')],
        ['late', t('Late')],
        ['mine', t('Mine')],
        ['solved', t('Solved')],
    ] as const;

    return (
        <>
            <Head title={t('Support requests')} />
            <PageBody>
                <PageHeader
                    title={t('Support requests')}
                    description={t(
                        'Requests from hotels, first-answer targets, satisfaction, saved replies.',
                    )}
                    actions={
                        tab === 'replies' && (
                            <Button onClick={() => setReply('new')}>
                                <Plus /> {t('New saved reply')}
                            </Button>
                        )
                    }
                />
                <Tabs tabs={tabs} active={tab} onSelect={setTab} />

                {tab === 'requests' && (
                    <>
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <StatTile
                                label={t('In progress')}
                                value={counts.open}
                            />
                            <StatTile
                                label={t('Late')}
                                value={
                                    <span
                                        className={
                                            counts.late > 0
                                                ? 'text-destructive'
                                                : ''
                                        }
                                    >
                                        {counts.late}
                                    </span>
                                }
                            />
                            <StatTile
                                label={t('Assigned to me')}
                                value={counts.mine}
                            />
                            <StatTile
                                label={t('Satisfaction (90 days)')}
                                value={
                                    total > 0
                                        ? `${Math.round((100 * satisfaction.good) / total)} %`
                                        : '—'
                                }
                                hint={
                                    total > 0
                                        ? t(':count ratings', { count: total })
                                        : t('No ratings yet.')
                                }
                            />
                        </div>
                        <Section
                            flush
                            title={t('Requests')}
                            actions={
                                <div
                                    role="group"
                                    aria-label={t('Show')}
                                    className="inline-flex rounded-lg border bg-muted/40 p-0.5"
                                >
                                    {filters.map(([key, label]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            aria-pressed={filter === key}
                                            onClick={() =>
                                                router.get(
                                                    PlatformSupportController.index.url(
                                                        {
                                                            query: {
                                                                filter: key,
                                                            },
                                                        },
                                                    ),
                                                )
                                            }
                                            className={cn(
                                                'rounded-md px-3 py-1 text-xs transition-colors',
                                                filter === key
                                                    ? 'bg-card font-medium shadow-sm'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            }
                        >
                            <DataTable
                                rows={tickets}
                                rowKey={(tk) => tk.id}
                                empty={t('No requests here.')}
                                onRowClick={(tk) =>
                                    router.visit(
                                        PlatformSupportController.show.url(
                                            tk.id,
                                        ),
                                    )
                                }
                                columns={[
                                    {
                                        key: 'subject',
                                        header: t('Request'),
                                        cell: (tk) => (
                                            <div>
                                                <Link
                                                    href={PlatformSupportController.show.url(
                                                        tk.id,
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {tk.subject}
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    #{tk.id} · {tk.category}
                                                </div>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'hotel',
                                        header: t('Hotel'),
                                        cell: (tk) => tk.hotel ?? '—',
                                    },
                                    {
                                        key: 'flags',
                                        header: t('Status'),
                                        cell: (tk) => (
                                            <div className="flex flex-wrap items-center gap-1">
                                                <Badge variant="outline">
                                                    {tk.status}
                                                </Badge>
                                                {tk.priority !== 'normal' && (
                                                    <Badge variant="destructive">
                                                        {tk.priority}
                                                    </Badge>
                                                )}
                                                {tk.late && (
                                                    <Badge variant="destructive">
                                                        {t('Late')}
                                                    </Badge>
                                                )}
                                                {tk.rating && (
                                                    <span
                                                        aria-label={tk.rating}
                                                    >
                                                        {tk.rating === 'good'
                                                            ? '👍'
                                                            : '👎'}
                                                    </span>
                                                )}
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'assignee',
                                        header: t('Assigned to'),
                                        cell: (tk) =>
                                            tk.assignee ?? (
                                                <span className="text-muted-foreground">
                                                    {t('Nobody')}
                                                </span>
                                            ),
                                    },
                                    {
                                        key: 'due',
                                        header: t('Answer due'),
                                        cell: (tk) => (
                                            <span
                                                className={
                                                    tk.late
                                                        ? 'text-destructive'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {tk.due ?? '—'}
                                            </span>
                                        ),
                                    },
                                ]}
                            />
                        </Section>
                    </>
                )}

                {tab === 'replies' && (
                    <Section
                        flush
                        title={t('Saved replies')}
                        description={t(
                            'Ready-made answers agents insert in a reply. Click one to edit it.',
                        )}
                    >
                        <DataTable
                            rows={savedReplies}
                            rowKey={(r) => r.id}
                            empty={t('No saved replies yet.')}
                            onRowClick={setReply}
                            columns={[
                                {
                                    key: 'title',
                                    header: t('Title'),
                                    cell: (r) => (
                                        <span className="font-medium">
                                            {r.title}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'text',
                                    header: t('Text'),
                                    cell: (r) => (
                                        <span className="line-clamp-1 text-muted-foreground">
                                            {r.body.fr}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'settings' && (
                    <SettingsForm settings={settings} hotels={hotels} />
                )}
            </PageBody>

            <SidePanel
                open={reply !== null}
                onClose={() => setReply(null)}
                title={editing ? editing.title : t('New saved reply')}
            >
                {reply !== null && (
                    <ReplyForm
                        key={editing?.id ?? 'new'}
                        reply={editing}
                        onDone={() => setReply(null)}
                    />
                )}
            </SidePanel>
        </>
    );
}

PlatformSupport.layout = {
    breadcrumbs: [
        { title: 'Support requests', href: PlatformSupportController.index() },
    ],
};
