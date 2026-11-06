import { Head, router, useForm, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import OrderBoardController from '@/actions/App/Domains/Orders/Http/Controllers/OrderBoardController';
import RestaurantController from '@/actions/App/Domains/Restaurant/Http/Controllers/RestaurantController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type OrderCard = {
    id: string;
    reference: string;
    status: string;
    outlet: string | null;
    guest: string | null;
    where: string;
    payment: string;
    source: string;
    total: string;
    lines: { quantity: number; name: string }[];
    notes: string | null;
    eta_minutes: number | null;
    placed_at: string | null;
    waiting_minutes: number;
    next: { value: string; label: string }[];
};
type Props = {
    columns: { status: string; label: string; orders: OrderCard[] }[];
    closed: OrderCard[];
    outlets: {
        id: string;
        name: string;
        dishes: { id: number; name: string; price: string }[];
    }[];
    stays: { id: string; guest: string | null; rooms: string[] }[];
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function Card({ order }: { order: OrderCard }) {
    const act = (status: string) => {
        if (status === 'accepted') {
            const eta = window.prompt(
                t('Ready in how many minutes? (5, 10, 15, 20, 30, 45 or 60)'),
                '20',
            );
            if (eta) {
                router.post(
                    OrderBoardController.status.url(order.id),
                    { status, eta_minutes: Number(eta) },
                    { preserveScroll: true },
                );
            }

            return;
        }
        const reason =
            status === 'declined' || status === 'cancelled'
                ? window.prompt(t('Reason (optional)'))
                : null;
        router.post(
            OrderBoardController.status.url(order.id),
            { status, reason },
            { preserveScroll: true },
        );
    };

    return (
        <div className="flex flex-col gap-1 rounded-lg border bg-card p-3 text-sm print:break-inside-avoid">
            <div className="flex items-center justify-between gap-2">
                <span className="font-medium">{order.where}</span>
                <Badge
                    variant={
                        order.waiting_minutes > 30 && order.status === 'new'
                            ? 'destructive'
                            : 'outline'
                    }
                >
                    {t(':minutes min', { minutes: order.waiting_minutes })}
                </Badge>
            </div>
            <ul>
                {order.lines.map((l, i) => (
                    <li key={i}>
                        <b>{l.quantity}×</b> {l.name}
                    </li>
                ))}
            </ul>
            {order.notes && <p className="text-xs italic">{order.notes}</p>}
            <p className="text-xs text-muted-foreground">
                {order.guest} · {order.total} · {order.payment} · {order.source}{' '}
                · {order.reference}
                {order.eta_minutes !== null &&
                    ` · ${t('ready in :minutes min', { minutes: order.eta_minutes })}`}
            </p>
            <div className="flex flex-wrap gap-1 print:hidden">
                {order.next.map((n) => (
                    <Button
                        key={n.value}
                        size="sm"
                        variant={
                            n.value === 'declined' || n.value === 'cancelled'
                                ? 'ghost'
                                : 'outline'
                        }
                        onClick={() => act(n.value)}
                    >
                        {n.label}
                    </Button>
                ))}
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => window.print()}
                >
                    {t('Print')}
                </Button>
            </div>
        </div>
    );
}

function NewOrder({
    outlets,
    stays,
    onDone,
}: {
    outlets: Props['outlets'];
    stays: Props['stays'];
    onDone: () => void;
}) {
    const form = useForm({
        outlet_id: outlets[0]?.id ?? '',
        quantities: {} as Record<number, number>,
        delivery: 'room',
        booking_id: '',
        room_number: '',
        location_note: '',
        payment: 'on_delivery',
        source: 'phone',
        notes: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const outlet = outlets.find((o) => o.id === form.data.outlet_id);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            items: Object.entries(d.quantities)
                .filter(([, q]) => q > 0)
                .map(([id, q]) => ({ menu_item_id: Number(id), quantity: q })),
        }));
        form.post(OrderBoardController.store.url(), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-3"
        >
            <div className="grid gap-1">
                <Label>{t('Outlet')}</Label>
                <select
                    className={selectClass}
                    value={form.data.outlet_id}
                    onChange={(e) => form.setData('outlet_id', e.target.value)}
                >
                    {outlets.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-1">
                <Label>{t('Delivery')}</Label>
                <select
                    className={selectClass}
                    value={form.data.delivery}
                    onChange={(e) => form.setData('delivery', e.target.value)}
                >
                    <option value="room">{t('To the room')}</option>
                    <option value="outlet">{t('At the outlet')}</option>
                </select>
            </div>
            <div className="grid gap-1">
                <Label>{t('Taken')}</Label>
                <select
                    className={selectClass}
                    value={form.data.source}
                    onChange={(e) => form.setData('source', e.target.value)}
                >
                    <option value="phone">{t('Phone')}</option>
                    <option value="counter">{t('At the counter')}</option>
                </select>
            </div>
            <div className="grid gap-1">
                <Label>{t('Guest staying (optional)')}</Label>
                <select
                    className={selectClass}
                    value={form.data.booking_id}
                    onChange={(e) => {
                        const stay = stays.find((s) => s.id === e.target.value);
                        form.setData((d) => ({
                            ...d,
                            booking_id: e.target.value,
                            room_number: stay?.rooms[0] ?? d.room_number,
                            payment: e.target.value ? d.payment : 'on_delivery',
                        }));
                    }}
                >
                    <option value="">{t('Not staying / not known')}</option>
                    {stays.map((s) => (
                        <option key={s.id} value={s.id}>
                            {`${s.rooms.join(', ')} · ${s.guest ?? ''}`}
                        </option>
                    ))}
                </select>
            </div>
            {form.data.delivery === 'room' ? (
                <div className="grid gap-1">
                    <Label>{t('Room')}</Label>
                    <Input
                        value={form.data.room_number}
                        onChange={(e) =>
                            form.setData('room_number', e.target.value)
                        }
                    />
                </div>
            ) : (
                <div className="grid gap-1">
                    <Label>{t('Table or place')}</Label>
                    <Input
                        placeholder={t('e.g. Pool, table 4')}
                        value={form.data.location_note}
                        onChange={(e) =>
                            form.setData('location_note', e.target.value)
                        }
                    />
                </div>
            )}
            <div className="grid gap-1">
                <Label>{t('Payment')}</Label>
                <select
                    className={selectClass}
                    value={form.data.payment}
                    onChange={(e) => form.setData('payment', e.target.value)}
                >
                    <option value="on_delivery">{t('Paid on delivery')}</option>
                    <option
                        value="room_charge"
                        disabled={!form.data.booking_id}
                    >
                        {t('On the room bill')}
                    </option>
                </select>
            </div>
            <div className="grid gap-1 sm:col-span-3">
                <Label>{t('Dishes')}</Label>
                <div className="grid gap-1 sm:grid-cols-2">
                    {(outlet?.dishes ?? []).map((d) => (
                        <label
                            key={d.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Input
                                type="number"
                                min={0}
                                className="w-16"
                                value={form.data.quantities[d.id] ?? 0}
                                onChange={(e) =>
                                    form.setData('quantities', {
                                        ...form.data.quantities,
                                        [d.id]: Number(e.target.value),
                                    })
                                }
                            />
                            {d.name}{' '}
                            <span className="text-muted-foreground">
                                {d.price}
                            </span>
                        </label>
                    ))}
                    {(outlet?.dishes ?? []).length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('Nothing can be ordered from this outlet now.')}
                        </p>
                    )}
                </div>
                <InputError message={errors.items} />
            </div>
            <div className="grid gap-1 sm:col-span-2">
                <Label>{t('Notes')}</Label>
                <Input
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
            </div>
            <div className="self-end">
                <Button disabled={form.processing}>{t('Add order')}</Button>
            </div>
        </form>
    );
}

export default function RestaurantOrders({
    columns,
    closed,
    outlets,
    stays,
}: Props) {
    const [adding, setAdding] = useState(false);
    usePoll(10000, { only: ['columns', 'closed', 'stays'] });
    const newCount =
        columns.find((c) => c.status === 'new')?.orders.length ?? 0;
    const previous = useRef(newCount);

    useEffect(() => {
        if (
            newCount > previous.current &&
            typeof Notification !== 'undefined' &&
            Notification.permission === 'granted'
        ) {
            new Notification(t('New order'));
        }
        previous.current = newCount;
    }, [newCount]);

    return (
        <>
            <Head title={t('Orders')} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3 print:hidden">
                    <Heading
                        title={t('Orders')}
                        description={t(
                            'Room service, bar and restaurant orders. Refreshes every 10 seconds.',
                        )}
                    />
                    <div className="flex gap-2">
                        {typeof Notification !== 'undefined' &&
                            Notification.permission === 'default' && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        Notification.requestPermission()
                                    }
                                >
                                    {t('Alert me on this computer')}
                                </Button>
                            )}
                        {outlets.length > 0 && (
                            <Button
                                size="sm"
                                onClick={() => setAdding(!adding)}
                            >
                                {adding ? t('Close') : t('Take an order')}
                            </Button>
                        )}
                    </div>
                </div>
                {outlets.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Turn on ordering for an outlet in Outlets and opening hours.',
                        )}
                    </p>
                )}
                {adding && (
                    <NewOrder
                        outlets={outlets}
                        stays={stays}
                        onDone={() => setAdding(false)}
                    />
                )}
                <div className="grid gap-4 lg:grid-cols-3">
                    {columns.map((column) => (
                        <section
                            key={column.status}
                            className="flex flex-col gap-2"
                        >
                            <h2 className="font-medium">
                                {column.label}{' '}
                                <Badge variant="secondary">
                                    {column.orders.length}
                                </Badge>
                            </h2>
                            {column.orders.map((order) => (
                                <Card key={order.id} order={order} />
                            ))}
                        </section>
                    ))}
                </div>
                {closed.length > 0 && (
                    <section className="flex flex-col gap-2 print:hidden">
                        <Heading variant="small" title={t('Finished today')} />
                        <ul className="divide-y rounded-xl border text-sm">
                            {closed.map((o) => (
                                <li
                                    key={o.id}
                                    className="flex flex-wrap gap-3 px-3 py-2"
                                >
                                    <span className="font-medium">
                                        {o.reference}
                                    </span>
                                    <span>{o.where}</span>
                                    <span className="text-muted-foreground">
                                        {o.lines
                                            .map(
                                                (l) =>
                                                    `${l.quantity}× ${l.name}`,
                                            )
                                            .join(', ')}
                                    </span>
                                    <span className="ml-auto">{o.total}</span>
                                    <Badge variant="outline">{o.status}</Badge>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

RestaurantOrders.layout = {
    breadcrumbs: [
        { title: 'Restaurant & Bar', href: RestaurantController() },
        { title: 'Orders', href: OrderBoardController.index() },
    ],
};
