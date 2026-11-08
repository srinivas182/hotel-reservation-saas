import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AnnouncementController from '@/actions/App/Domains/Platform/Http/Controllers/AnnouncementController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Row = {
    id: number;
    title: Record<string, string>;
    body: Record<string, string>;
    level: string;
    countries: string[];
    plans: string[];
    hotels: string[];
    starts_at: string;
    ends_at: string;
    email: boolean;
    emailed: boolean;
    live: boolean;
};
type Props = {
    announcements: Row[];
    countries: string[];
    plans: string[];
    hotels: { id: string; name: string }[];
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function Multi({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: { value: string; label: string }[];
    value: string[];
    onChange: (v: string[]) => void;
}) {
    return (
        <label className="grid gap-0.5 text-xs">
            {label}
            <select
                multiple
                className="h-20 rounded-md border border-input bg-transparent px-2 text-xs"
                value={value}
                onChange={(e) =>
                    onChange(
                        Array.from(e.target.selectedOptions).map(
                            (o) => o.value,
                        ),
                    )
                }
            >
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function AnnouncementForm({
    row,
    props,
    onDone,
}: {
    row?: Row;
    props: Props;
    onDone?: () => void;
}) {
    const now = new Date();
    const later = new Date(now.getTime() + 7 * 86400000);
    const form = useForm({
        title: { fr: row?.title.fr ?? '', en: row?.title.en ?? '' },
        body: { fr: row?.body.fr ?? '', en: row?.body.en ?? '' },
        level: row?.level ?? 'info',
        countries: row?.countries ?? [],
        plans: row?.plans ?? [],
        hotels: row?.hotels ?? [],
        starts_at: row?.starts_at ?? now.toISOString().slice(0, 16),
        ends_at: row?.ends_at ?? later.toISOString().slice(0, 16),
        email: row?.email ?? false,
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                const options = {
                    preserveScroll: true,
                    onSuccess: () => onDone?.(),
                };
                if (row) {
                    form.put(
                        AnnouncementController.update.url(row.id),
                        options,
                    );
                } else {
                    form.post(AnnouncementController.store.url(), options);
                }
            }}
            className="grid gap-2 rounded-xl border p-3 text-sm sm:grid-cols-2"
        >
            {(['fr', 'en'] as const).map((l) => (
                <div key={l} className="grid gap-1">
                    <Input
                        aria-label={`${t('Title')} ${l.toUpperCase()}`}
                        placeholder={`${t('Title')} (${l.toUpperCase()})`}
                        value={form.data.title[l]}
                        onChange={(e) =>
                            form.setData('title', {
                                ...form.data.title,
                                [l]: e.target.value,
                            })
                        }
                    />
                    <textarea
                        aria-label={`${t('Message')} ${l.toUpperCase()}`}
                        placeholder={`${t('Message')} (${l.toUpperCase()})`}
                        className="min-h-16 rounded-md border border-input bg-transparent px-3 py-2"
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
            <InputError
                className="sm:col-span-2"
                message={
                    errors['title.fr'] ?? errors['body.fr'] ?? errors.ends_at
                }
            />
            <div className="flex flex-wrap items-end gap-2 sm:col-span-2">
                <select
                    aria-label={t('Level')}
                    className={selectClass}
                    value={form.data.level}
                    onChange={(e) => form.setData('level', e.target.value)}
                >
                    <option value="info">{t('Information')}</option>
                    <option value="warning">{t('Warning')}</option>
                    <option value="maintenance">
                        {t('Maintenance (cannot be hidden)')}
                    </option>
                </select>
                <Multi
                    label={t('Countries (none: all)')}
                    options={props.countries.map((c) => ({
                        value: c,
                        label: c,
                    }))}
                    value={form.data.countries}
                    onChange={(v) => form.setData('countries', v)}
                />
                <Multi
                    label={t('Plans')}
                    options={props.plans.map((p) => ({ value: p, label: p }))}
                    value={form.data.plans}
                    onChange={(v) => form.setData('plans', v)}
                />
                <Multi
                    label={t('Hotels')}
                    options={props.hotels.map((h) => ({
                        value: h.id,
                        label: h.name,
                    }))}
                    value={form.data.hotels}
                    onChange={(v) => form.setData('hotels', v)}
                />
                <label className="grid gap-0.5 text-xs">
                    {t('From')}
                    <Input
                        type="datetime-local"
                        value={form.data.starts_at}
                        onChange={(e) =>
                            form.setData('starts_at', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-0.5 text-xs">
                    {t('Until')}
                    <Input
                        type="datetime-local"
                        value={form.data.ends_at}
                        onChange={(e) =>
                            form.setData('ends_at', e.target.value)
                        }
                    />
                </label>
                <label className="flex items-center gap-1 text-xs">
                    <input
                        type="checkbox"
                        checked={form.data.email}
                        onChange={(e) =>
                            form.setData('email', e.target.checked)
                        }
                    />
                    {t('Also email the owners')}
                </label>
                <Button size="sm" disabled={form.processing}>
                    {row ? t('Save') : t('Publish')}
                </Button>
            </div>
        </form>
    );
}

export default function Announcements(props: Props) {
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title={t('Announcements')} />
            <div className="flex max-w-5xl flex-col gap-4 p-4 text-sm">
                <div className="flex items-start justify-between">
                    <Heading
                        title={t('Announcements')}
                        description={t(
                            'Banners in hotels’ screens: new features, price changes, maintenance.',
                        )}
                    />
                    <Button
                        variant="outline"
                        onClick={() => setAdding(!adding)}
                    >
                        {adding ? t('Close') : t('New announcement')}
                    </Button>
                </div>
                {adding && (
                    <AnnouncementForm
                        props={props}
                        onDone={() => setAdding(false)}
                    />
                )}
                {props.announcements.map((a) => (
                    <details key={a.id} className="rounded-xl border p-2">
                        <summary className="flex cursor-pointer flex-wrap items-center gap-2">
                            <strong>{a.title.fr}</strong>
                            <Badge
                                variant={
                                    a.level === 'maintenance'
                                        ? 'destructive'
                                        : 'outline'
                                }
                            >
                                {a.level}
                            </Badge>
                            {a.live && <Badge>{t('Showing now')}</Badge>}
                            {a.emailed && (
                                <Badge variant="secondary">
                                    {t('Emailed')}
                                </Badge>
                            )}
                            <span className="text-xs text-muted-foreground">
                                {a.starts_at} → {a.ends_at}
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="ml-auto text-destructive"
                                onClick={() =>
                                    window.confirm(
                                        t('Delete this announcement?'),
                                    ) &&
                                    router.delete(
                                        AnnouncementController.destroy.url(
                                            a.id,
                                        ),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('Delete')}
                            </Button>
                        </summary>
                        <AnnouncementForm row={a} props={props} />
                    </details>
                ))}
            </div>
        </>
    );
}

Announcements.layout = {
    breadcrumbs: [
        { title: 'Announcements', href: AnnouncementController.index() },
    ],
};
