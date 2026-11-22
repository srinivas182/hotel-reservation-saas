import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download } from 'lucide-react';
import { useState } from 'react';
import AuditLogController from '@/actions/App/Domains/Platform/Http/Controllers/AuditLogController';
import {
    DataTable,
    Field,
    PageBody,
    PageHeader,
    SidePanel,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Filters = {
    hotel: string | null;
    user: string | null;
    event: string | null;
    from: string | null;
    to: string | null;
};
type Props = {
    filters: Filters;
    hotels: { id: string; name: string }[];
    entries: {
        id: number;
        at: string | null;
        event: string | null;
        description: string;
        hotel_id: string | null;
        by: string | null;
        ip: string | null;
        properties: unknown;
    }[];
    pagination: { current: number; last: number; total: number };
    canExport: boolean;
};

type Entry = Props['entries'][number];

export default function Audit({
    filters,
    hotels,
    entries,
    pagination,
    canExport,
}: Props) {
    const [f, setF] = useState<Filters>(filters);
    const [open, setOpen] = useState<Entry | null>(null);
    const query = (extra: Record<string, string | number> = {}) =>
        Object.fromEntries(
            Object.entries({ ...f, ...extra }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        );
    const hotelName = (id: string | null) =>
        hotels.find((h) => h.id === id)?.name ?? '';
    const page = (n: number) =>
        router.get(
            AuditLogController.index.url({
                query: query({ page: n }) as Record<string, string>,
            }),
        );
    const hasDetails = (e: Entry) =>
        e.properties !== null &&
        !(Array.isArray(e.properties) && e.properties.length === 0);

    return (
        <>
            <Head title={t('Audit log')} />
            <PageBody wide>
                <PageHeader
                    title={t('Audit log')}
                    description={t(
                        'Who did what, and when, in every hotel and on the platform.',
                    )}
                    actions={
                        canExport && (
                            <Button variant="outline" asChild>
                                <a
                                    href={AuditLogController.export.url({
                                        query: query() as Record<
                                            string,
                                            string
                                        >,
                                    })}
                                >
                                    <Download /> {t('Export CSV')}
                                </a>
                            </Button>
                        )
                    }
                />
                <section className="overflow-hidden rounded-xl border bg-card">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                AuditLogController.index.url({
                                    query: query() as Record<string, string>,
                                }),
                            );
                        }}
                        className="grid items-end gap-3 border-b bg-muted/30 px-5 py-4 sm:grid-cols-2 lg:grid-cols-[1.2fr_1.2fr_1fr_9rem_9rem_auto]"
                    >
                        <Field label={t('Hotel')}>
                            <select
                                className="h-9"
                                value={f.hotel ?? ''}
                                onChange={(e) =>
                                    setF({
                                        ...f,
                                        hotel: e.target.value || null,
                                    })
                                }
                            >
                                <option value="">{t('All hotels')}</option>
                                {hotels.map((h) => (
                                    <option key={h.id} value={h.id}>
                                        {h.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('User email')}>
                            <Input
                                value={f.user ?? ''}
                                onChange={(e) =>
                                    setF({ ...f, user: e.target.value || null })
                                }
                            />
                        </Field>
                        <Field label={t('Event')}>
                            <Input
                                placeholder={t('Event (e.g. billing.)')}
                                value={f.event ?? ''}
                                onChange={(e) =>
                                    setF({
                                        ...f,
                                        event: e.target.value || null,
                                    })
                                }
                            />
                        </Field>
                        <Field label={t('From')}>
                            <Input
                                type="date"
                                value={f.from ?? ''}
                                onChange={(e) =>
                                    setF({ ...f, from: e.target.value || null })
                                }
                            />
                        </Field>
                        <Field label={t('To')}>
                            <Input
                                type="date"
                                value={f.to ?? ''}
                                onChange={(e) =>
                                    setF({ ...f, to: e.target.value || null })
                                }
                            />
                        </Field>
                        <Button>{t('Search')}</Button>
                    </form>
                    <div className="px-5 py-2 text-xs text-muted-foreground">
                        {t(':total entries', { total: pagination.total })}
                    </div>
                    <DataTable
                        rows={entries}
                        rowKey={(e) => e.id}
                        empty={t('No entries match these filters.')}
                        onRowClick={setOpen}
                        columns={[
                            {
                                key: 'at',
                                header: t('When'),
                                className: 'whitespace-nowrap',
                                cell: (e) => (
                                    <span className="text-muted-foreground tabular-nums">
                                        {e.at}
                                    </span>
                                ),
                            },
                            {
                                key: 'what',
                                header: t('What'),
                                cell: (e) => (
                                    <div>
                                        <div>{e.description}</div>
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {e.event}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'hotel',
                                header: t('Hotel'),
                                cell: (e) => hotelName(e.hotel_id) || '—',
                            },
                            {
                                key: 'by',
                                header: t('By'),
                                cell: (e) => e.by ?? '—',
                            },
                            {
                                key: 'details',
                                header: '',
                                align: 'right',
                                cell: (e) =>
                                    hasDetails(e) && (
                                        <span className="text-xs text-brand">
                                            {t('Details')}
                                        </span>
                                    ),
                            },
                        ]}
                    />
                    {pagination.last > 1 && (
                        <div className="flex items-center justify-end gap-2 border-t px-5 py-3 text-sm">
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={pagination.current <= 1}
                                aria-label={t('Previous page')}
                                onClick={() => page(pagination.current - 1)}
                            >
                                <ChevronLeft />
                            </Button>
                            <span className="text-muted-foreground tabular-nums">
                                {pagination.current} / {pagination.last}
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={pagination.current >= pagination.last}
                                aria-label={t('Next page')}
                                onClick={() => page(pagination.current + 1)}
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                    )}
                </section>
            </PageBody>
            <SidePanel
                open={open !== null}
                onClose={() => setOpen(null)}
                title={open?.description ?? ''}
                description={open?.at ?? undefined}
            >
                {open && (
                    <div className="grid gap-4 text-sm">
                        <dl className="grid grid-cols-[7rem_1fr] gap-x-4 gap-y-2">
                            <dt className="text-muted-foreground">
                                {t('Event')}
                            </dt>
                            <dd className="font-mono text-xs">{open.event}</dd>
                            <dt className="text-muted-foreground">
                                {t('Hotel')}
                            </dt>
                            <dd>{hotelName(open.hotel_id) || '—'}</dd>
                            <dt className="text-muted-foreground">{t('By')}</dt>
                            <dd>{open.by ?? '—'}</dd>
                            <dt className="text-muted-foreground">IP</dt>
                            <dd className="tabular-nums">{open.ip ?? '—'}</dd>
                        </dl>
                        {hasDetails(open) && (
                            <pre className="overflow-x-auto rounded-lg bg-muted/50 p-3 text-xs">
                                {JSON.stringify(open.properties, null, 2)}
                            </pre>
                        )}
                    </div>
                )}
            </SidePanel>
        </>
    );
}

Audit.layout = {
    breadcrumbs: [{ title: 'Audit log', href: AuditLogController.index() }],
};
