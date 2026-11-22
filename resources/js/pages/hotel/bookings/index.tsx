import { Head, Link, router } from '@inertiajs/react';
import { Download, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import BookingController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingController';
import BookingExportController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingExportController';
import { BookingStatusBadge } from '@/components/bookings/status-badge';
import {
    DataTable,
    Field,
    PageBody,
    PageHeader,
    Pagination,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Row = {
    id: string;
    reference: string;
    status: string;
    status_label: string;
    channel_label: string;
    guest_name: string | null;
    guest_phone: string | null;
    category: string | null;
    check_in: string;
    check_out: string;
    nights: number;
    rooms: number;
    guests: number;
    total: string;
};

type Option = { value: string; label: string };

type Props = {
    bookings: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: {
        search?: string;
        status?: string;
        channel?: string;
        from?: string;
        to?: string;
    };
    statuses: Option[];
    channels: Option[];
    canCreate: boolean;
    canExport: boolean;
};

export default function BookingsIndex({
    bookings,
    filters,
    statuses,
    channels,
    canCreate,
    canExport,
}: Props) {
    const [form, setForm] = useState({
        search: filters.search ?? '',
        status: filters.status ?? '',
        channel: filters.channel ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const query = Object.fromEntries(
            Object.entries(form).filter(([, value]) => value !== ''),
        );
        router.get(
            BookingController.index.url({ query }),
            {},
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title={t('Bookings')} />
            <PageBody wide>
                <PageHeader
                    title={t('Bookings')}
                    description={t(':count bookings', {
                        count: bookings.total,
                    })}
                    actions={
                        <>
                            {canExport && (
                                <Button
                                    variant="outline"
                                    disabled={!form.from || !form.to}
                                    title={t(
                                        'Choose From and To dates to export',
                                    )}
                                    onClick={() => {
                                        window.location.href =
                                            BookingExportController.url({
                                                query: {
                                                    from: form.from,
                                                    to: form.to,
                                                    ...(form.status
                                                        ? {
                                                              status: form.status,
                                                          }
                                                        : {}),
                                                },
                                            });
                                    }}
                                >
                                    <Download /> {t('Export CSV')}
                                </Button>
                            )}
                            {canCreate && (
                                <Button asChild>
                                    <Link href={BookingController.create()}>
                                        <Plus /> {t('New booking')}
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <section className="overflow-hidden rounded-xl border bg-card">
                    <form
                        onSubmit={submit}
                        className="grid items-end gap-3 border-b bg-muted/30 px-5 py-4 sm:grid-cols-2 lg:grid-cols-[1.6fr_1fr_1fr_9rem_9rem_auto]"
                    >
                        <Field label={t('Search bookings')}>
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    className="pl-9"
                                    type="search"
                                    aria-label={t('Search bookings')}
                                    placeholder={t('Reference, name or phone')}
                                    value={form.search}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            search: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        </Field>
                        <Field label={t('Status')}>
                            <select
                                className="h-9"
                                value={form.status}
                                onChange={(e) =>
                                    setForm({ ...form, status: e.target.value })
                                }
                            >
                                <option value="">{t('All statuses')}</option>
                                {statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('Channel')}>
                            <select
                                className="h-9"
                                value={form.channel}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        channel: e.target.value,
                                    })
                                }
                            >
                                <option value="">{t('All channels')}</option>
                                {channels.map((c) => (
                                    <option key={c.value} value={c.value}>
                                        {c.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('From')}>
                            <Input
                                type="date"
                                value={form.from}
                                onChange={(e) =>
                                    setForm({ ...form, from: e.target.value })
                                }
                            />
                        </Field>
                        <Field label={t('To')}>
                            <Input
                                type="date"
                                value={form.to}
                                onChange={(e) =>
                                    setForm({ ...form, to: e.target.value })
                                }
                            />
                        </Field>
                        <Button>{t('Search')}</Button>
                    </form>
                    <DataTable
                        rows={bookings.data}
                        rowKey={(b) => b.id}
                        empty={t('No bookings found.')}
                        onRowClick={(b) =>
                            router.visit(BookingController.show(b.id))
                        }
                        columns={[
                            {
                                key: 'guest',
                                header: t('Guest'),
                                cell: (b) => (
                                    <div>
                                        <div className="font-medium">
                                            {b.guest_name ?? '—'}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            <Link
                                                href={BookingController.show(
                                                    b.id,
                                                )}
                                                className="hover:underline"
                                            >
                                                {b.reference}
                                            </Link>{' '}
                                            · {b.channel_label}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'stay',
                                header: t('Stay'),
                                cell: (b) => (
                                    <div>
                                        <div className="tabular-nums">
                                            {b.check_in} → {b.check_out}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {t(
                                                ':nights night(s) · :guests guest(s)',
                                                {
                                                    nights: b.nights,
                                                    guests: b.guests,
                                                },
                                            )}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'room',
                                header: t('Room category'),
                                cell: (b) =>
                                    `${b.category ?? '—'} × ${b.rooms}`,
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (b) => (
                                    <BookingStatusBadge
                                        status={b.status}
                                        label={b.status_label}
                                    />
                                ),
                            },
                            {
                                key: 'total',
                                header: t('Total'),
                                align: 'right',
                                cell: (b) => (
                                    <span className="font-medium">
                                        {b.total}
                                    </span>
                                ),
                            },
                        ]}
                    />
                    <Pagination
                        links={bookings.links}
                        label={t('Pagination')}
                    />
                </section>
            </PageBody>
        </>
    );
}

BookingsIndex.layout = {
    breadcrumbs: [{ title: 'Bookings', href: BookingController.index() }],
};
