import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import AnnouncementController from '@/actions/App/Domains/Platform/Http/Controllers/AnnouncementController';
import {
    ChipSelect,
    DataTable,
    Field,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    Switch,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { askConfirm } from '@/lib/ask';

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
            className="grid gap-6"
        >
            <FieldGroup title={t('Message')}>
                <Field label={t('Level')}>
                    <select
                        className="h-9"
                        value={form.data.level}
                        onChange={(e) => form.setData('level', e.target.value)}
                    >
                        <option value="info">{t('Information')}</option>
                        <option value="warning">{t('Warning')}</option>
                        <option value="maintenance">
                            {t('Maintenance (cannot be hidden)')}
                        </option>
                    </select>
                </Field>
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
                                    ? t('Message (French)')
                                    : t('Message (English)')
                            }
                            error={errors[`body.${l}`]}
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
            <FieldGroup
                title={t('Who sees it')}
                description={t('Nothing chosen: every hotel.')}
            >
                <Field label={t('Countries')}>
                    <ChipSelect
                        label={t('Countries')}
                        options={props.countries.map((c) => ({
                            value: c,
                            label: c,
                        }))}
                        value={form.data.countries}
                        onChange={(v) => form.setData('countries', v)}
                    />
                </Field>
                <Field label={t('Plans')}>
                    <ChipSelect
                        label={t('Plans')}
                        options={props.plans.map((p) => ({
                            value: p,
                            label: p,
                        }))}
                        value={form.data.plans}
                        onChange={(v) => form.setData('plans', v)}
                    />
                </Field>
                <Field label={t('Hotels')}>
                    <ChipSelect
                        label={t('Hotels')}
                        options={props.hotels.map((h) => ({
                            value: h.id,
                            label: h.name,
                        }))}
                        value={form.data.hotels}
                        onChange={(v) => form.setData('hotels', v)}
                    />
                </Field>
            </FieldGroup>
            <FieldGroup title={t('When')}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('From')}>
                        <Input
                            type="datetime-local"
                            value={form.data.starts_at}
                            onChange={(e) =>
                                form.setData('starts_at', e.target.value)
                            }
                        />
                    </Field>
                    <Field label={t('Until')} error={errors.ends_at}>
                        <Input
                            type="datetime-local"
                            value={form.data.ends_at}
                            onChange={(e) =>
                                form.setData('ends_at', e.target.value)
                            }
                        />
                    </Field>
                </div>
                <Switch
                    checked={form.data.email}
                    onChange={(v) => form.setData('email', v)}
                    label={t('Also email the owners')}
                />
            </FieldGroup>
            <div className="flex items-center justify-between gap-2 border-t pt-4">
                {row ? (
                    <Button
                        type="button"
                        variant="ghost"
                        className="text-destructive"
                        onClick={async () =>
                            (await askConfirm(
                                t('Delete this announcement?'),
                            )) &&
                            router.delete(
                                AnnouncementController.destroy.url(row.id),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => onDone?.(),
                                },
                            )
                        }
                    >
                        {t('Delete')}
                    </Button>
                ) : (
                    <span />
                )}
                <Button disabled={form.processing}>
                    {row ? t('Save') : t('Publish')}
                </Button>
            </div>
        </form>
    );
}

export default function Announcements(props: Props) {
    const [editing, setEditing] = useState<Row | 'new' | null>(null);
    const row = editing === 'new' || editing === null ? undefined : editing;

    return (
        <>
            <Head title={t('Announcements')} />
            <PageBody>
                <PageHeader
                    title={t('Announcements')}
                    description={t(
                        'Banners in hotels’ screens: new features, price changes, maintenance.',
                    )}
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus /> {t('New announcement')}
                        </Button>
                    }
                />
                <Section
                    flush
                    title={t('Announcements')}
                    description={t('Click one to edit it.')}
                >
                    <DataTable
                        rows={props.announcements}
                        rowKey={(a) => a.id}
                        empty={t('No announcements yet.')}
                        onRowClick={setEditing}
                        columns={[
                            {
                                key: 'title',
                                header: t('Title'),
                                cell: (a) => (
                                    <span className="font-medium">
                                        {a.title.fr}
                                    </span>
                                ),
                            },
                            {
                                key: 'level',
                                header: t('Level'),
                                cell: (a) => (
                                    <Badge
                                        variant={
                                            a.level === 'maintenance'
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        {a.level}
                                    </Badge>
                                ),
                            },
                            {
                                key: 'when',
                                header: t('When'),
                                cell: (a) => (
                                    <span className="text-xs text-muted-foreground">
                                        {a.starts_at} → {a.ends_at}
                                    </span>
                                ),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (a) => (
                                    <div className="flex flex-wrap gap-1">
                                        {a.live ? (
                                            <Badge>{t('Showing now')}</Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                {t('Not showing')}
                                            </Badge>
                                        )}
                                        {a.emailed && (
                                            <Badge variant="secondary">
                                                {t('Emailed')}
                                            </Badge>
                                        )}
                                    </div>
                                ),
                            },
                        ]}
                    />
                </Section>
            </PageBody>
            <SidePanel
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={row ? row.title.fr : t('New announcement')}
            >
                {editing !== null && (
                    <AnnouncementForm
                        key={row?.id ?? 'new'}
                        row={row}
                        props={props}
                        onDone={() => setEditing(null)}
                    />
                )}
            </SidePanel>
        </>
    );
}

Announcements.layout = {
    breadcrumbs: [
        { title: 'Announcements', href: AnnouncementController.index() },
    ],
};
