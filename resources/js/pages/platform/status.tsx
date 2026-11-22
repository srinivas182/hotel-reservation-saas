import { Head, useForm } from '@inertiajs/react';
import { ExternalLink, Plus } from 'lucide-react';
import { useState } from 'react';
import PlatformStatusController from '@/actions/App/Domains/Status/Http/Controllers/PlatformStatusController';
import InputError from '@/components/input-error';
import {
    ChipSelect,
    Field,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Lang = { fr: string; en: string };
type Notice = {
    id: number;
    title: Lang;
    level: string;
    components: string[];
    countries: string[] | null;
    starts_at: string;
    ends_at: string | null;
    resolved_at: string | null;
    updates: { state: string; body: Lang; at: string | null }[];
};
type Props = {
    components: { key: string; label: string; state: string }[];
    notices: Notice[];
    countries: string[];
    subscribers: number;
};

const stateLabel = (s: string) =>
    ({
        operational: t('Operational'),
        maintenance: t('Maintenance'),
        degraded: t('Slower than usual'),
        outage: t('Not working'),
    })[s] ?? s;

function NewNotice({
    components,
    countries,
    onDone,
}: {
    components: Props['components'];
    countries: string[];
    onDone: () => void;
}) {
    const form = useForm({
        title: { fr: '', en: '' },
        body: { fr: '', en: '' },
        level: 'degraded',
        components: [] as string[],
        countries: [] as string[],
        starts_at: '',
        ends_at: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    starts_at: d.starts_at || null,
                    ends_at: d.ends_at || null,
                    countries: d.countries.length ? d.countries : null,
                }));
                form.post(PlatformStatusController.store.url(), {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        onDone();
                    },
                });
            }}
            className="grid gap-6"
        >
            <FieldGroup title={t('What is happening')}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('Level')}>
                        <select
                            className="h-9"
                            value={form.data.level}
                            onChange={(e) =>
                                form.setData('level', e.target.value)
                            }
                        >
                            <option value="degraded">
                                {t('Slower than usual')}
                            </option>
                            <option value="outage">{t('Not working')}</option>
                            <option value="maintenance">
                                {t('Maintenance')}
                            </option>
                        </select>
                    </Field>
                </div>
                <div className="grid gap-2">
                    <span className="text-xs font-medium">{t('Services')}</span>
                    <ChipSelect
                        label={t('Services')}
                        value={form.data.components}
                        onChange={(v) => form.setData('components', v)}
                        options={components.map((c) => ({
                            value: c.key,
                            label: c.label,
                        }))}
                    />
                    <InputError message={errors.components} />
                </div>
            </FieldGroup>
            <FieldGroup
                title={t('Message')}
                description={t(
                    'Shown on the status page, in the hotels’ app and in the alert emails.',
                )}
            >
                {(['fr', 'en'] as const).map((l) => (
                    <div key={l} className="grid gap-3">
                        <Field
                            label={
                                l === 'fr'
                                    ? t('Title (French)')
                                    : t('Title (English)')
                            }
                            error={errors[`title.${l}`]}
                        >
                            <Input
                                value={form.data.title[l]}
                                onChange={(e) =>
                                    form.setData('title', {
                                        ...form.data.title,
                                        [l]: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field
                            label={
                                l === 'fr'
                                    ? t('First update (French)')
                                    : t('First update (English)')
                            }
                        >
                            <textarea
                                className="min-h-20"
                                value={form.data.body[l]}
                                onChange={(e) =>
                                    form.setData('body', {
                                        ...form.data.body,
                                        [l]: e.target.value,
                                    })
                                }
                            />
                        </Field>
                    </div>
                ))}
            </FieldGroup>
            <FieldGroup title={t('Where and when')}>
                <Field
                    label={t('Countries')}
                    hint={t('None selected: everywhere.')}
                >
                    <ChipSelect
                        label={t('Countries')}
                        value={form.data.countries}
                        onChange={(v) => form.setData('countries', v)}
                        options={countries.map((c) => ({ value: c, label: c }))}
                    />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('Starts')} hint={t('Empty: now')}>
                        <Input
                            type="datetime-local"
                            value={form.data.starts_at}
                            onChange={(e) =>
                                form.setData('starts_at', e.target.value)
                            }
                        />
                    </Field>
                    <Field label={t('Planned end (maintenance)')}>
                        <Input
                            type="datetime-local"
                            value={form.data.ends_at}
                            onChange={(e) =>
                                form.setData('ends_at', e.target.value)
                            }
                        />
                    </Field>
                </div>
            </FieldGroup>
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button disabled={form.processing}>
                    {t('Publish on the status page')}
                </Button>
            </div>
        </form>
    );
}

function UpdateForm({ notice }: { notice: Notice }) {
    const form = useForm({ body: { fr: '', en: '' }, state: 'identified' });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(PlatformStatusController.update.url(notice.id), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
            className="grid gap-3 border-t bg-muted/30 p-4 sm:grid-cols-2"
        >
            {(['fr', 'en'] as const).map((l) => (
                <textarea
                    key={l}
                    aria-label={`${t('Update')} ${l}`}
                    placeholder={`${t('Update')} (${l})`}
                    className="min-h-16"
                    value={form.data.body[l]}
                    onChange={(e) =>
                        form.setData('body', {
                            ...form.data.body,
                            [l]: e.target.value,
                        })
                    }
                />
            ))}
            <select
                aria-label={t('State')}
                className="h-9"
                value={form.data.state}
                onChange={(e) => form.setData('state', e.target.value)}
            >
                <option value="investigating">{t('Investigating')}</option>
                <option value="identified">{t('Cause found')}</option>
                <option value="monitoring">{t('Fixed, watching')}</option>
                <option value="resolved">{t('Resolved')}</option>
            </select>
            <div className="flex justify-end">
                <Button size="sm" disabled={form.processing}>
                    {t('Post the update')}
                </Button>
            </div>
        </form>
    );
}

const dot = (state: string) =>
    ({
        operational: 'bg-emerald-500',
        maintenance: 'bg-sky-500',
        degraded: 'bg-amber-500',
        outage: 'bg-red-500',
    })[state] ?? 'bg-muted-foreground';

function NoticeCard({ notice }: { notice: Notice }) {
    const open = notice.resolved_at === null;

    return (
        <article className="overflow-hidden rounded-xl border bg-card">
            <header className="flex flex-wrap items-center gap-2 px-4 py-3">
                <span
                    className={cn('size-2 rounded-full', dot(notice.level))}
                />
                <h3 className="font-medium">{notice.title.fr}</h3>
                <Badge variant="outline">{stateLabel(notice.level)}</Badge>
                {notice.resolved_at ? (
                    <Badge variant="secondary">{t('Resolved')}</Badge>
                ) : (
                    <Badge variant="destructive">{t('Ongoing')}</Badge>
                )}
                <span className="ml-auto text-xs text-muted-foreground">
                    {notice.starts_at}
                    {notice.ends_at ? ` → ${notice.ends_at}` : ''}
                </span>
            </header>
            {notice.updates.length > 0 && (
                <ol className="space-y-3 border-t px-4 py-3">
                    {notice.updates.map((u, i) => (
                        <li
                            key={i}
                            className="grid gap-0.5 sm:grid-cols-[10rem_1fr]"
                        >
                            <span className="text-xs text-muted-foreground">
                                {u.at} · {u.state}
                            </span>
                            <span>{u.body.fr}</span>
                        </li>
                    ))}
                </ol>
            )}
            {open && <UpdateForm notice={notice} />}
        </article>
    );
}

export default function PlatformStatus({
    components,
    notices,
    countries,
    subscribers,
}: Props) {
    const [creating, setCreating] = useState(false);
    const active = notices.filter((n) => n.resolved_at === null);
    const past = notices.filter((n) => n.resolved_at !== null);

    return (
        <>
            <Head title={t('Service status')} />
            <PageBody>
                <PageHeader
                    title={t('Service status')}
                    description={t(
                        ':count people get alerts by email. Hotels see active notices in the app.',
                        { count: subscribers },
                    )}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a
                                    href="/status"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink /> {t('See the status page')}
                                </a>
                            </Button>
                            <Button onClick={() => setCreating(true)}>
                                <Plus /> {t('New incident or maintenance')}
                            </Button>
                        </>
                    }
                />

                <Section flush title={t('Services')}>
                    <ul className="divide-y">
                        {components.map((c) => (
                            <li
                                key={c.key}
                                className="flex items-center justify-between gap-3 px-5 py-3"
                            >
                                <span>{c.label}</span>
                                <span className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <span
                                        className={cn(
                                            'size-2 rounded-full',
                                            dot(c.state),
                                        )}
                                    />
                                    {stateLabel(c.state)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>

                <section className="flex flex-col gap-3">
                    <h2 className="font-medium">{t('Ongoing')}</h2>
                    {active.length === 0 ? (
                        <p className="rounded-xl border border-dashed px-5 py-6 text-center text-sm text-muted-foreground">
                            {t('No ongoing incident or maintenance.')}
                        </p>
                    ) : (
                        active.map((n) => <NoticeCard key={n.id} notice={n} />)
                    )}
                </section>

                {past.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="font-medium">{t('History')}</h2>
                        {past.map((n) => (
                            <NoticeCard key={n.id} notice={n} />
                        ))}
                    </section>
                )}
            </PageBody>

            <SidePanel
                open={creating}
                onClose={() => setCreating(false)}
                title={t('New incident or maintenance')}
                description={t(
                    'Published at once on the status page; people who subscribed get an email.',
                )}
            >
                <NewNotice
                    components={components}
                    countries={countries}
                    onDone={() => setCreating(false)}
                />
            </SidePanel>
        </>
    );
}

PlatformStatus.layout = {
    breadcrumbs: [
        { title: 'Service status', href: PlatformStatusController.index() },
    ],
};
