import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import OrderBoardController from '@/actions/App/Domains/Orders/Http/Controllers/OrderBoardController';
import MenuController from '@/actions/App/Domains/Restaurant/Http/Controllers/MenuController';
import OutletController from '@/actions/App/Domains/Restaurant/Http/Controllers/OutletController';
import RestaurantController from '@/actions/App/Domains/Restaurant/Http/Controllers/RestaurantController';
import TableBookingController from '@/actions/App/Domains/Restaurant/Http/Controllers/TableBookingController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type Booking = {
    id: string;
    reference: string;
    time: string;
    covers: number;
    guest: string | null;
    phone: string | null;
    status: string;
    status_label: string;
    source: string;
    notes: string | null;
    next: { value: string; label: string }[];
};
type Period = {
    id: number;
    name: string;
    hours: string;
    covers: number;
    bookings: Booking[];
};
type Slot = {
    period_id: number;
    period: string;
    time: string;
    free_seats: number;
    available: boolean;
};

type Props = {
    outlets: { id: string; name: string; seats: number }[];
    outlet: { id: string; name: string; seats: number } | null;
    date: string;
    periods: Period[];
    occupancy: Record<string, number>;
    slots?: Slot[];
    can: { bookings: boolean; manage: boolean; menu: boolean; orders: boolean };
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const shift = (date: string, days: number) => {
    const d = new Date(`${date}T12:00:00`);
    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
};

function QuickAdd({
    outlet,
    date,
    slots,
}: {
    outlet: { id: string };
    date: string;
    slots: Slot[] | undefined;
}) {
    const form = useForm({
        outlet_id: outlet.id,
        date,
        time: '',
        covers: 2,
        first_name: '',
        last_name: '',
        phone: '',
        source: 'phone',
        notes: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const loadSlots = (covers: number, day: string) =>
        router.reload({
            only: ['slots'],
            data: { outlet: outlet.id, date: day, covers },
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(TableBookingController.store.url(), {
            preserveScroll: true,
            onSuccess: () =>
                form.reset('first_name', 'last_name', 'phone', 'notes', 'time'),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-4"
        >
            <div className="grid gap-1">
                <Label htmlFor="qa-date">{t('Date')}</Label>
                <Input
                    id="qa-date"
                    type="date"
                    value={form.data.date}
                    onChange={(e) => {
                        form.setData('date', e.target.value);
                        loadSlots(form.data.covers, e.target.value);
                    }}
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="qa-covers">{t('People')}</Label>
                <Input
                    id="qa-covers"
                    type="number"
                    min={1}
                    value={form.data.covers}
                    onChange={(e) => {
                        form.setData('covers', Number(e.target.value));
                        loadSlots(Number(e.target.value), form.data.date);
                    }}
                />
            </div>
            <div className="grid gap-1 sm:col-span-2">
                <Label htmlFor="qa-time">{t('Time')}</Label>
                <select
                    id="qa-time"
                    className={selectClass}
                    value={form.data.time}
                    onFocus={() =>
                        slots === undefined &&
                        loadSlots(form.data.covers, form.data.date)
                    }
                    onChange={(e) => form.setData('time', e.target.value)}
                >
                    <option value="">{t('Choose a time')}</option>
                    {(slots ?? []).map((s) => (
                        <option
                            key={`${s.period_id}-${s.time}`}
                            value={s.time}
                            disabled={!s.available}
                        >
                            {`${s.time} · ${s.period} · ${t(':seats seats free', { seats: s.free_seats })}`}
                        </option>
                    ))}
                </select>
                <InputError message={errors.time} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="qa-first">{t('First name')}</Label>
                <Input
                    id="qa-first"
                    value={form.data.first_name}
                    onChange={(e) => form.setData('first_name', e.target.value)}
                />
                <InputError message={errors.first_name} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="qa-last">{t('Last name')}</Label>
                <Input
                    id="qa-last"
                    value={form.data.last_name}
                    onChange={(e) => form.setData('last_name', e.target.value)}
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="qa-phone">{t('Phone')}</Label>
                <Input
                    id="qa-phone"
                    type="tel"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="qa-source">{t('Booked by')}</Label>
                <select
                    id="qa-source"
                    className={selectClass}
                    value={form.data.source}
                    onChange={(e) => form.setData('source', e.target.value)}
                >
                    <option value="phone">{t('Phone')}</option>
                    <option value="walk_in">{t('Walk-in')}</option>
                </select>
            </div>
            <div className="grid gap-1 sm:col-span-3">
                <Label htmlFor="qa-notes">
                    {t('Notes (occasion, allergies the guest mentioned…)')}
                </Label>
                <Input
                    id="qa-notes"
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
            </div>
            <div className="self-end">
                <Button disabled={form.processing || form.data.time === ''}>
                    {t('Book the table')}
                </Button>
            </div>
        </form>
    );
}

export default function Restaurant({
    outlets,
    outlet,
    date,
    periods,
    occupancy,
    slots,
    can,
}: Props) {
    const [adding, setAdding] = useState(false);
    const go = (params: Record<string, string>) =>
        router.get(
            RestaurantController.url({
                query: { outlet: outlet?.id ?? '', date, ...params },
            }),
            {},
            { preserveScroll: true },
        );
    const move = (booking: Booking, status: string) => {
        const reason =
            status === 'declined' || status === 'cancelled'
                ? window.prompt(t('Reason (optional)'))
                : null;
        router.post(
            TableBookingController.status.url(booking.id),
            { status, reason },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={t('Restaurant & Bar')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title={t('Restaurant & Bar')}
                        description={
                            outlet
                                ? t(':name · :seats seats', {
                                      name: outlet.name,
                                      seats: outlet.seats,
                                  })
                                : t(
                                      'Add an outlet to start taking table bookings.',
                                  )
                        }
                    />
                    <div className="flex gap-4 text-sm">
                        {can.orders && (
                            <Link
                                href={OrderBoardController.index()}
                                className="underline"
                            >
                                {t('Orders')}
                            </Link>
                        )}
                        {can.menu && (
                            <Link
                                href={MenuController.index()}
                                className="underline"
                            >
                                {t('Menu')}
                            </Link>
                        )}
                        {can.manage && (
                            <Link
                                href={OutletController.index()}
                                className="underline"
                            >
                                {t('Outlets and opening hours')}
                            </Link>
                        )}
                    </div>
                </div>

                {outlet && (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            {outlets.length > 1 && (
                                <select
                                    aria-label={t('Outlet')}
                                    className={selectClass}
                                    value={outlet.id}
                                    onChange={(e) =>
                                        go({ outlet: e.target.value })
                                    }
                                >
                                    {outlets.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => go({ date: shift(date, -1) })}
                            >
                                ‹
                            </Button>
                            <Input
                                type="date"
                                aria-label={t('Date')}
                                className="w-44"
                                value={date}
                                onChange={(e) => go({ date: e.target.value })}
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => go({ date: shift(date, 1) })}
                            >
                                ›
                            </Button>
                            {can.bookings && (
                                <Button
                                    size="sm"
                                    className="ml-auto"
                                    onClick={() => setAdding(!adding)}
                                >
                                    {adding
                                        ? t('Close')
                                        : t('New table booking')}
                                </Button>
                            )}
                        </div>

                        {adding && (
                            <QuickAdd
                                outlet={outlet}
                                date={date}
                                slots={slots}
                            />
                        )}

                        {Object.keys(occupancy).length > 0 && (
                            <div className="overflow-x-auto">
                                <div className="flex min-w-max gap-1">
                                    {Object.entries(occupancy).map(
                                        ([time, used]) => (
                                            <div
                                                key={time}
                                                className="flex w-10 flex-col items-center text-[10px]"
                                            >
                                                <div className="flex h-16 w-6 items-end rounded bg-muted">
                                                    <div
                                                        className={
                                                            used >= outlet.seats
                                                                ? 'w-full rounded bg-destructive'
                                                                : 'w-full rounded bg-primary'
                                                        }
                                                        style={{
                                                            height: `${Math.min(100, (100 * used) / outlet.seats)}%`,
                                                        }}
                                                    />
                                                </div>
                                                <span>{time}</span>
                                                <span className="text-muted-foreground">
                                                    {used}
                                                </span>
                                            </div>
                                        ),
                                    )}
                                </div>
                            </div>
                        )}

                        {periods.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                {t('No service on this day.')}
                            </p>
                        )}
                        {periods.map((period) => (
                            <section
                                key={period.id}
                                className="flex flex-col gap-2"
                            >
                                <div className="flex items-center gap-2">
                                    <h2 className="font-medium">
                                        {period.name}
                                    </h2>
                                    <span className="text-sm text-muted-foreground">
                                        {period.hours}
                                    </span>
                                    <Badge variant="secondary">
                                        {t(':covers covers', {
                                            covers: period.covers,
                                        })}
                                    </Badge>
                                </div>
                                {period.bookings.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('No bookings.')}
                                    </p>
                                )}
                                <ul className="divide-y rounded-xl border">
                                    {period.bookings.map((b) => (
                                        <li
                                            key={b.id}
                                            className="flex flex-wrap items-center gap-3 px-4 py-2 text-sm"
                                        >
                                            <span className="w-12 font-medium">
                                                {b.time}
                                            </span>
                                            <span className="w-10">
                                                {b.covers}×
                                            </span>
                                            <span className="min-w-40 flex-1">
                                                {b.guest}{' '}
                                                <span className="text-muted-foreground">
                                                    {b.phone}
                                                </span>
                                                {b.notes && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {b.notes}
                                                    </span>
                                                )}
                                            </span>
                                            <Badge
                                                variant={
                                                    b.status === 'requested'
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                            >
                                                {b.status_label}
                                            </Badge>
                                            <span className="text-xs text-muted-foreground">
                                                {b.reference} · {b.source}
                                            </span>
                                            {can.bookings &&
                                                b.next.map((n) => (
                                                    <Button
                                                        key={n.value}
                                                        size="sm"
                                                        variant={
                                                            n.value ===
                                                                'cancelled' ||
                                                            n.value ===
                                                                'declined' ||
                                                            n.value ===
                                                                'no_show'
                                                                ? 'ghost'
                                                                : 'outline'
                                                        }
                                                        onClick={() =>
                                                            move(b, n.value)
                                                        }
                                                    >
                                                        {n.label}
                                                    </Button>
                                                ))}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </>
                )}
            </div>
        </>
    );
}

Restaurant.layout = {
    breadcrumbs: [{ title: 'Restaurant & Bar', href: RestaurantController() }],
};
