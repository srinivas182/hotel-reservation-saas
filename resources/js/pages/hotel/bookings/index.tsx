import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import BookingController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingController';
import { BookingStatusBadge } from '@/components/bookings/status-badge';
import Heading from '@/components/heading';
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
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs';

export default function BookingsIndex({
    bookings,
    filters,
    statuses,
    channels,
    canCreate,
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
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('Bookings')}
                        description={t(':count bookings', {
                            count: bookings.total,
                        })}
                    />
                    {canCreate && (
                        <Button asChild>
                            <Link href={BookingController.create()}>
                                <Plus /> {t('New booking')}
                            </Link>
                        </Button>
                    )}
                </div>

                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-2"
                >
                    <Input
                        className="w-64"
                        type="search"
                        placeholder={t('Reference, name or phone')}
                        aria-label={t('Search bookings')}
                        value={form.search}
                        onChange={(e) =>
                            setForm({ ...form, search: e.target.value })
                        }
                    />
                    <select
                        aria-label={t('Status')}
                        className={selectClass}
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
                    <select
                        aria-label={t('Channel')}
                        className={selectClass}
                        value={form.channel}
                        onChange={(e) =>
                            setForm({ ...form, channel: e.target.value })
                        }
                    >
                        <option value="">{t('All channels')}</option>
                        {channels.map((c) => (
                            <option key={c.value} value={c.value}>
                                {c.label}
                            </option>
                        ))}
                    </select>
                    <Input
                        className="w-40"
                        type="date"
                        aria-label={t('From')}
                        value={form.from}
                        onChange={(e) =>
                            setForm({ ...form, from: e.target.value })
                        }
                    />
                    <Input
                        className="w-40"
                        type="date"
                        aria-label={t('To')}
                        value={form.to}
                        onChange={(e) =>
                            setForm({ ...form, to: e.target.value })
                        }
                    />
                    <Button variant="outline">{t('Search')}</Button>
                </form>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    {t('Reference')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Guest')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Stay')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Room category')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Status')}
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    {t('Total')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {bookings.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        {t('No bookings found.')}
                                    </td>
                                </tr>
                            )}
                            {bookings.data.map((booking) => (
                                <tr key={booking.id} className="border-t">
                                    <td className="px-4 py-3">
                                        <Link
                                            href={BookingController.show(
                                                booking.id,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {booking.reference}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {booking.channel_label}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div>{booking.guest_name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {booking.guest_phone}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {booking.check_in} → {booking.check_out}
                                        <div className="text-xs text-muted-foreground">
                                            {t(
                                                ':nights night(s) · :guests guest(s)',
                                                {
                                                    nights: booking.nights,
                                                    guests: booking.guests,
                                                },
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {booking.category} × {booking.rooms}
                                    </td>
                                    <td className="px-4 py-3">
                                        <BookingStatusBadge
                                            status={booking.status}
                                            label={booking.status_label}
                                        />
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {booking.total}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {bookings.links.length > 3 && (
                    <nav
                        className="flex flex-wrap gap-1"
                        aria-label={t('Pagination')}
                    >
                        {bookings.links.map((link, index) => (
                            <Button
                                key={index}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={link.url === null}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveState: true },
                                    )
                                }
                            >
                                <span
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            </Button>
                        ))}
                    </nav>
                )}
            </div>
        </>
    );
}

BookingsIndex.layout = {
    breadcrumbs: [{ title: 'Bookings', href: BookingController.index() }],
};
