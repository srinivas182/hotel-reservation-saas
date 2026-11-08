import { Head, useForm } from '@inertiajs/react';
import PlatformStatusController from '@/actions/App/Domains/Status/Http/Controllers/PlatformStatusController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

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

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
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
}: {
    components: Props['components'];
    countries: string[];
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
                    onSuccess: () => form.reset(),
                });
            }}
            className="grid gap-2 rounded-xl border p-3 sm:grid-cols-2"
        >
            {(['fr', 'en'] as const).map((l) => (
                <div key={l} className="grid gap-2">
                    <Input
                        aria-label={`${t('Title')} ${l}`}
                        placeholder={`${t('Title')} (${l})`}
                        value={form.data.title[l]}
                        onChange={(e) =>
                            form.setData('title', {
                                ...form.data.title,
                                [l]: e.target.value,
                            })
                        }
                    />
                    <textarea
                        aria-label={`${t('First update')} ${l}`}
                        placeholder={`${t('First update')} (${l})`}
                        className="min-h-20 rounded-md border border-input bg-transparent p-2"
                        value={form.data.body[l]}
                        onChange={(e) =>
                            form.setData('body', {
                                ...form.data.body,
                                [l]: e.target.value,
                            })
                        }
                    />
                </div>
            ))}
            <label className="grid gap-0.5 text-xs">
                {t('Level')}
                <select
                    className={selectClass}
                    value={form.data.level}
                    onChange={(e) => form.setData('level', e.target.value)}
                >
                    <option value="degraded">{t('Slower than usual')}</option>
                    <option value="outage">{t('Not working')}</option>
                    <option value="maintenance">{t('Maintenance')}</option>
                </select>
            </label>
            <fieldset className="flex flex-wrap gap-3 text-xs">
                <legend className="mb-1">{t('Services')}</legend>
                {components.map((c) => (
                    <label key={c.key} className="flex items-center gap-1">
                        <input
                            type="checkbox"
                            checked={form.data.components.includes(c.key)}
                            onChange={(e) =>
                                form.setData(
                                    'components',
                                    e.target.checked
                                        ? [...form.data.components, c.key]
                                        : form.data.components.filter(
                                              (x) => x !== c.key,
                                          ),
                                )
                            }
                        />
                        {c.label}
                    </label>
                ))}
            </fieldset>
            <label className="grid gap-0.5 text-xs">
                {t('Countries (none = everywhere)')}
                <select
                    multiple
                    className="h-24 rounded-md border border-input bg-transparent px-2 text-sm"
                    value={form.data.countries}
                    onChange={(e) =>
                        form.setData(
                            'countries',
                            Array.from(e.target.selectedOptions).map(
                                (o) => o.value,
                            ),
                        )
                    }
                >
                    {countries.map((c) => (
                        <option key={c} value={c}>
                            {c}
                        </option>
                    ))}
                </select>
            </label>
            <div className="grid gap-2 sm:grid-cols-2">
                <label className="grid gap-0.5 text-xs">
                    {t('Starts (empty = now)')}
                    <Input
                        type="datetime-local"
                        value={form.data.starts_at}
                        onChange={(e) =>
                            form.setData('starts_at', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-0.5 text-xs">
                    {t('Planned end (maintenance)')}
                    <Input
                        type="datetime-local"
                        value={form.data.ends_at}
                        onChange={(e) =>
                            form.setData('ends_at', e.target.value)
                        }
                    />
                </label>
            </div>
            <InputError message={errors.components ?? errors['title.fr']} />
            <div>
                <Button size="sm" disabled={form.processing}>
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
            className="mt-2 grid gap-2 sm:grid-cols-2"
        >
            {(['fr', 'en'] as const).map((l) => (
                <textarea
                    key={l}
                    aria-label={`${t('Update')} ${l}`}
                    placeholder={`${t('Update')} (${l})`}
                    className="min-h-16 rounded-md border border-input bg-transparent p-2"
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
                className={selectClass}
                value={form.data.state}
                onChange={(e) => form.setData('state', e.target.value)}
            >
                <option value="investigating">{t('Investigating')}</option>
                <option value="identified">{t('Cause found')}</option>
                <option value="monitoring">{t('Fixed, watching')}</option>
                <option value="resolved">{t('Resolved')}</option>
            </select>
            <div>
                <Button size="sm" disabled={form.processing}>
                    {t('Post the update')}
                </Button>
            </div>
        </form>
    );
}

export default function PlatformStatus({
    components,
    notices,
    countries,
    subscribers,
}: Props) {
    return (
        <>
            <Head title={t('Service status')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('Service status')}
                    description={t(
                        ':count people get alerts by email. Hotels see active notices in the app.',
                        { count: subscribers },
                    )}
                />
                <ul className="flex flex-wrap gap-2">
                    {components.map((c) => (
                        <li key={c.key}>
                            <Badge
                                variant={
                                    c.state === 'operational'
                                        ? 'secondary'
                                        : 'destructive'
                                }
                            >
                                {c.label}: {stateLabel(c.state)}
                            </Badge>
                        </li>
                    ))}
                    <li>
                        <a
                            href="/status"
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            {t('See the status page')}
                        </a>
                    </li>
                </ul>
                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('New incident or maintenance')}
                    />
                    <NewNotice components={components} countries={countries} />
                </section>
                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Notices')} />
                    {notices.map((n) => (
                        <details
                            key={n.id}
                            className="rounded-xl border p-2"
                            open={n.resolved_at === null}
                        >
                            <summary className="cursor-pointer">
                                {n.title.fr} · {stateLabel(n.level)}{' '}
                                {n.resolved_at ? `· ${t('resolved')}` : ''}
                            </summary>
                            <ul className="mt-1 text-xs text-muted-foreground">
                                {n.updates.map((u, i) => (
                                    <li key={i}>
                                        {u.at} · {u.state} · {u.body.fr}
                                    </li>
                                ))}
                            </ul>
                            {n.resolved_at === null && (
                                <UpdateForm notice={n} />
                            )}
                        </details>
                    ))}
                </section>
            </div>
        </>
    );
}

PlatformStatus.layout = {
    breadcrumbs: [
        { title: 'Service status', href: PlatformStatusController.index() },
    ],
};
