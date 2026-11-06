import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import OutletController from '@/actions/App/Domains/Restaurant/Http/Controllers/OutletController';
import RestaurantController from '@/actions/App/Domains/Restaurant/Http/Controllers/RestaurantController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type PeriodRow = {
    weekday: number;
    name: Record<string, string>;
    starts_at: string;
    ends_at: string;
    slot_minutes: number;
    last_seating_minutes: number;
};
type OutletRow = {
    id: string;
    name: Record<string, string>;
    type: string;
    seats: number;
    default_duration_minutes: number;
    slot_arrival_limit: number | null;
    auto_confirm: boolean;
    auto_confirm_max_party: number;
    active: boolean;
    orders_enabled: boolean;
    periods: PeriodRow[];
    closures: { id: number; date: string; reason: string | null }[];
};
type RoomCharges = {
    room_charge_enabled: boolean;
    order_limit: number | null;
    stay_limit: number | null;
    accept_outside_orders: boolean;
    payment_methods: string[];
};
type Props = {
    outlets: OutletRow[];
    types: { value: string; label: string }[];
    locales: string[];
    roomCharges: RoomCharges;
    allMethods: { value: string; label: string }[];
    currency: string;
};

const WEEKDAYS = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];
const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const blank = (locales: string[]) => ({
    name: Object.fromEntries(locales.map((l) => [l, ''])) as Record<
        string,
        string
    >,
    type: 'restaurant',
    seats: 40,
    default_duration_minutes: 90,
    slot_arrival_limit: null as number | null,
    auto_confirm: true,
    auto_confirm_max_party: 6,
    active: true,
    orders_enabled: false,
});

function OutletForm({
    outlet,
    types,
    locales,
    onDone,
}: {
    outlet?: OutletRow;
    types: Props['types'];
    locales: string[];
    onDone?: () => void;
}) {
    const form = useForm(
        outlet
            ? {
                  name: outlet.name,
                  type: outlet.type,
                  seats: outlet.seats,
                  default_duration_minutes: outlet.default_duration_minutes,
                  slot_arrival_limit: outlet.slot_arrival_limit,
                  auto_confirm: outlet.auto_confirm,
                  auto_confirm_max_party: outlet.auto_confirm_max_party,
                  active: outlet.active,
                  orders_enabled: outlet.orders_enabled,
              }
            : blank(locales),
    );
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onDone?.() };
        if (outlet) {
            form.put(OutletController.update.url(outlet.id), options);
        } else {
            form.post(OutletController.store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-4">
            {locales.map((l) => (
                <div key={l} className="grid gap-1 sm:col-span-2">
                    <Label htmlFor={`name-${outlet?.id ?? 'new'}-${l}`}>
                        {t('Name (:language)', { language: l.toUpperCase() })}
                    </Label>
                    <Input
                        id={`name-${outlet?.id ?? 'new'}-${l}`}
                        value={form.data.name[l] ?? ''}
                        onChange={(e) =>
                            form.setData('name', {
                                ...form.data.name,
                                [l]: e.target.value,
                            })
                        }
                    />
                </div>
            ))}
            <InputError className="sm:col-span-4" message={errors.name} />
            <div className="grid gap-1">
                <Label>{t('Type')}</Label>
                <select
                    className={selectClass}
                    value={form.data.type}
                    onChange={(e) => form.setData('type', e.target.value)}
                >
                    {types.map((ty) => (
                        <option key={ty.value} value={ty.value}>
                            {ty.label}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-1">
                <Label>{t('Seats')}</Label>
                <Input
                    type="number"
                    min={1}
                    value={form.data.seats}
                    onChange={(e) =>
                        form.setData('seats', Number(e.target.value))
                    }
                />
            </div>
            <div className="grid gap-1">
                <Label>{t('Table kept for (minutes)')}</Label>
                <Input
                    type="number"
                    min={15}
                    value={form.data.default_duration_minutes}
                    onChange={(e) =>
                        form.setData(
                            'default_duration_minutes',
                            Number(e.target.value),
                        )
                    }
                />
            </div>
            <div className="grid gap-1">
                <Label>
                    {t('Most guests arriving at one time (optional)')}
                </Label>
                <Input
                    type="number"
                    min={1}
                    value={form.data.slot_arrival_limit ?? ''}
                    onChange={(e) =>
                        form.setData(
                            'slot_arrival_limit',
                            e.target.value === ''
                                ? null
                                : Number(e.target.value),
                        )
                    }
                />
            </div>
            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.auto_confirm}
                    onChange={(e) =>
                        form.setData('auto_confirm', e.target.checked)
                    }
                />
                {t('Confirm WhatsApp bookings automatically up to')}
                <Input
                    type="number"
                    min={1}
                    className="w-20"
                    value={form.data.auto_confirm_max_party}
                    onChange={(e) =>
                        form.setData(
                            'auto_confirm_max_party',
                            Number(e.target.value),
                        )
                    }
                />
                {t('people')}
            </label>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.active}
                    onChange={(e) => form.setData('active', e.target.checked)}
                />
                {t('Taking bookings')}
            </label>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.orders_enabled}
                    onChange={(e) =>
                        form.setData('orders_enabled', e.target.checked)
                    }
                />
                {t('Taking food and drink orders')}
            </label>
            <div className="self-end">
                <Button disabled={form.processing}>
                    {outlet ? t('Save') : t('Add outlet')}
                </Button>
            </div>
        </form>
    );
}

function Periods({
    outlet,
    locales,
}: {
    outlet: OutletRow;
    locales: string[];
}) {
    const form = useForm<{ periods: PeriodRow[] }>({ periods: outlet.periods });
    const errors = form.errors as Record<string, string | undefined>;
    const set = (i: number, patch: Partial<PeriodRow>) =>
        form.setData(
            'periods',
            form.data.periods.map((p, j) => (j === i ? { ...p, ...patch } : p)),
        );
    const add = (weekday: number) =>
        form.setData('periods', [
            ...form.data.periods,
            {
                weekday,
                name: Object.fromEntries(locales.map((l) => [l, ''])),
                starts_at: '19:00',
                ends_at: '22:30',
                slot_minutes: 30,
                last_seating_minutes: 60,
            },
        ]);
    const copyToAll = (weekday: number) => {
        const source = form.data.periods.filter((p) => p.weekday === weekday);
        form.setData(
            'periods',
            [1, 2, 3, 4, 5, 6, 7].flatMap((d) =>
                source.map((p) => ({ ...p, weekday: d })),
            ),
        );
    };

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(OutletController.periods.url(outlet.id), {
                    preserveScroll: true,
                });
            }}
            className="flex flex-col gap-2"
        >
            {WEEKDAYS.map((day, index) => {
                const weekday = index + 1;
                const rows = form.data.periods
                    .map((p, i) => ({ p, i }))
                    .filter(({ p }) => p.weekday === weekday);

                return (
                    <div
                        key={day}
                        className="flex flex-col gap-1 rounded-lg border p-2 text-sm"
                    >
                        <div className="flex items-center gap-2">
                            <span className="w-28 font-medium">{t(day)}</span>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => add(weekday)}
                            >
                                {t('Add a service')}
                            </Button>
                            {rows.length > 0 && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => copyToAll(weekday)}
                                >
                                    {t('Same every day')}
                                </Button>
                            )}
                        </div>
                        {rows.map(({ p, i }) => (
                            <div
                                key={i}
                                className="flex flex-wrap items-center gap-2"
                            >
                                <Input
                                    className="w-40"
                                    aria-label={t('Service name')}
                                    placeholder={t('e.g. Dinner')}
                                    value={p.name[locales[0]] ?? ''}
                                    onChange={(e) =>
                                        set(i, {
                                            name: {
                                                ...p.name,
                                                [locales[0]]: e.target.value,
                                            },
                                        })
                                    }
                                />
                                {locales.slice(1).map((l) => (
                                    <Input
                                        key={l}
                                        className="w-32"
                                        aria-label={t(
                                            'Service name (:language)',
                                            { language: l.toUpperCase() },
                                        )}
                                        placeholder={l.toUpperCase()}
                                        value={p.name[l] ?? ''}
                                        onChange={(e) =>
                                            set(i, {
                                                name: {
                                                    ...p.name,
                                                    [l]: e.target.value,
                                                },
                                            })
                                        }
                                    />
                                ))}
                                <Input
                                    type="time"
                                    className="w-28"
                                    aria-label={t('From')}
                                    value={p.starts_at}
                                    onChange={(e) =>
                                        set(i, { starts_at: e.target.value })
                                    }
                                />
                                <Input
                                    type="time"
                                    className="w-28"
                                    aria-label={t('To')}
                                    value={p.ends_at}
                                    onChange={(e) =>
                                        set(i, { ends_at: e.target.value })
                                    }
                                />
                                <select
                                    aria-label={t('Every')}
                                    className={selectClass}
                                    value={p.slot_minutes}
                                    onChange={(e) =>
                                        set(i, {
                                            slot_minutes: Number(
                                                e.target.value,
                                            ),
                                        })
                                    }
                                >
                                    {[15, 30, 60].map((m) => (
                                        <option key={m} value={m}>
                                            {t('every :minutes min', {
                                                minutes: m,
                                            })}
                                        </option>
                                    ))}
                                </select>
                                <Input
                                    type="number"
                                    className="w-24"
                                    aria-label={t(
                                        'Last seating before closing (min)',
                                    )}
                                    value={p.last_seating_minutes}
                                    onChange={(e) =>
                                        set(i, {
                                            last_seating_minutes: Number(
                                                e.target.value,
                                            ),
                                        })
                                    }
                                />
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="text-destructive"
                                    onClick={() =>
                                        form.setData(
                                            'periods',
                                            form.data.periods.filter(
                                                (_, j) => j !== i,
                                            ),
                                        )
                                    }
                                >
                                    {t('Remove')}
                                </Button>
                                <InputError
                                    message={errors[`periods.${i}.ends_at`]}
                                />
                            </div>
                        ))}
                    </div>
                );
            })}
            <p className="text-xs text-muted-foreground">
                {t(
                    'The last number is the last seating, in minutes before the service ends.',
                )}
            </p>
            <div>
                <Button disabled={form.processing}>{t('Save services')}</Button>
            </div>
        </form>
    );
}

function Closures({ outlet }: { outlet: OutletRow }) {
    const form = useForm({ date: '', reason: '' });

    return (
        <div className="flex flex-col gap-2 text-sm">
            <ul>
                {outlet.closures.map((c) => (
                    <li key={c.id} className="flex items-center gap-2">
                        {c.date} {c.reason && `· ${c.reason}`}
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.delete(
                                    OutletController.reopen.url(c.id),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('Remove')}
                        </Button>
                    </li>
                ))}
            </ul>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(OutletController.close.url(outlet.id), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
                className="flex flex-wrap gap-2"
            >
                <Input
                    type="date"
                    aria-label={t('Closed on')}
                    className="w-44"
                    value={form.data.date}
                    onChange={(e) => form.setData('date', e.target.value)}
                />
                <Input
                    aria-label={t('Reason')}
                    placeholder={t('Reason (e.g. private event)')}
                    className="w-64"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
                <Button
                    size="sm"
                    variant="outline"
                    disabled={form.data.date === ''}
                >
                    {t('Close on this date')}
                </Button>
            </form>
        </div>
    );
}

function RoomChargesForm({
    settings,
    methods,
    currency,
}: {
    settings: RoomCharges;
    methods: { value: string; label: string }[];
    currency: string;
}) {
    const form = useForm(settings);
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(OutletController.roomCharges.url(), {
                    preserveScroll: true,
                });
            }}
            className="grid gap-3 rounded-xl border p-4 text-sm sm:grid-cols-2"
        >
            <label className="flex items-center gap-2 sm:col-span-2">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.room_charge_enabled}
                    onChange={(e) =>
                        form.setData('room_charge_enabled', e.target.checked)
                    }
                />
                {t('Guests staying can put orders on their room bill')}
            </label>
            <div className="grid gap-1">
                <Label>
                    {t('Limit per order (:currency, empty for none)', {
                        currency,
                    })}
                </Label>
                <Input
                    type="number"
                    min={0}
                    value={form.data.order_limit ?? ''}
                    onChange={(e) =>
                        form.setData(
                            'order_limit',
                            e.target.value === ''
                                ? null
                                : Number(e.target.value),
                        )
                    }
                />
            </div>
            <div className="grid gap-1">
                <Label>
                    {t('Limit per stay (:currency, empty for none)', {
                        currency,
                    })}
                </Label>
                <Input
                    type="number"
                    min={0}
                    value={form.data.stay_limit ?? ''}
                    onChange={(e) =>
                        form.setData(
                            'stay_limit',
                            e.target.value === ''
                                ? null
                                : Number(e.target.value),
                        )
                    }
                />
            </div>
            <label className="flex items-center gap-2 sm:col-span-2">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.accept_outside_orders}
                    onChange={(e) =>
                        form.setData('accept_outside_orders', e.target.checked)
                    }
                />
                {t(
                    'Accept WhatsApp orders from people not staying (paid on delivery)',
                )}
            </label>
            <fieldset className="flex flex-wrap gap-3 sm:col-span-2">
                <legend className="mb-1 font-medium">
                    {t('Payment methods recorded at the hotel')}
                </legend>
                {methods.map((m) => (
                    <label key={m.value} className="flex items-center gap-1">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={form.data.payment_methods.includes(
                                m.value,
                            )}
                            onChange={() =>
                                form.setData(
                                    'payment_methods',
                                    form.data.payment_methods.includes(m.value)
                                        ? form.data.payment_methods.filter(
                                              (v) => v !== m.value,
                                          )
                                        : [
                                              ...form.data.payment_methods,
                                              m.value,
                                          ],
                                )
                            }
                        />
                        {m.label}
                    </label>
                ))}
                <InputError message={errors.payment_methods} />
            </fieldset>
            <div>
                <Button disabled={form.processing}>{t('Save')}</Button>
            </div>
        </form>
    );
}

export default function RestaurantOutlets({
    outlets,
    types,
    locales,
    roomCharges,
    allMethods,
    currency,
}: Props) {
    const [adding, setAdding] = useState(outlets.length === 0);

    return (
        <>
            <Head title={t('Outlets and opening hours')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-3">
                    <Heading
                        title={t('Outlets and opening hours')}
                        description={t(
                            'Restaurants, bars and terraces that take table bookings.',
                        )}
                    />
                    <Button
                        variant="outline"
                        onClick={() => setAdding(!adding)}
                    >
                        {adding ? t('Close') : t('Add outlet')}
                    </Button>
                </div>
                {adding && (
                    <section className="rounded-xl border p-4">
                        <OutletForm
                            types={types}
                            locales={locales}
                            onDone={() => setAdding(false)}
                        />
                    </section>
                )}
                <Heading variant="small" title={t('Room bill and payments')} />
                <RoomChargesForm
                    settings={roomCharges}
                    methods={allMethods}
                    currency={currency}
                />
                {outlets.map((outlet) => (
                    <section
                        key={outlet.id}
                        className="flex flex-col gap-4 rounded-xl border p-4"
                    >
                        <h2 className="font-medium">
                            {Object.values(outlet.name)[0]}
                        </h2>
                        <OutletForm
                            outlet={outlet}
                            types={types}
                            locales={locales}
                        />
                        <Heading variant="small" title={t('Services')} />
                        <Periods outlet={outlet} locales={locales} />
                        <Heading variant="small" title={t('Closed dates')} />
                        <Closures outlet={outlet} />
                    </section>
                ))}
            </div>
        </>
    );
}

RestaurantOutlets.layout = {
    breadcrumbs: [
        { title: 'Restaurant & Bar', href: RestaurantController() },
        { title: 'Outlets and opening hours', href: OutletController.index() },
    ],
};
