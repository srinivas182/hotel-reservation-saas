import { Head, router, useForm } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import CalendarController from '@/actions/App/Domains/Availability/Http/Controllers/CalendarController';
import InventoryController from '@/actions/App/Domains/Availability/Http/Controllers/InventoryController';
import RateOverrideController from '@/actions/App/Domains/Rates/Http/Controllers/RateOverrideController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { TryAStay } from '@/components/rates/try-a-stay';
import type { QuoteResult } from '@/components/rates/try-a-stay';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type DateColumn = {
    date: string;
    weekday: string;
    day: string;
    weekend: boolean;
    today: boolean;
};
type Cell = {
    available: number;
    sellable: number;
    held: number;
    booked: number;
    held_back: number;
    stop_sell: boolean;
    rate: string | null;
    label: string | null;
};
type Hold = {
    id: string;
    category: string | null;
    check_in: string;
    check_out: string;
    rooms: number;
    source: string;
    note: string | null;
    expires_at: string;
};

type Props = {
    start: string;
    days: number;
    previous: string;
    next: string;
    today: string;
    currency: string;
    dates: DateColumn[];
    categories: { id: string; code: string; name: string }[];
    cells: Record<string, Record<string, Cell>>;
    holds: Hold[];
    quote?: QuoteResult;
    can: { manage: boolean; rates: boolean };
};

type Action = 'stop_on' | 'stop_off' | 'hold_back' | 'rate';

function cellTone(cell: Cell): string {
    if (cell.stop_sell) {
        return 'bg-muted text-muted-foreground line-through';
    }

    if (cell.available === 0) {
        return 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-100';
    }

    if (cell.sellable > 0 && cell.available / cell.sellable <= 0.25) {
        return 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100';
    }

    return 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100';
}

function RangeActions({ props }: { props: Props }) {
    const form = useForm({
        category_ids: props.categories.map((c) => c.id),
        from: props.start,
        to:
            props.dates[Math.min(6, props.dates.length - 1)]?.date ??
            props.start,
        action: 'stop_on' as Action,
        rooms: 1,
        rate: '',
        label: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const { category_ids, from, to, action, rooms, rate, label } =
            form.data;
        const options = { preserveScroll: true };

        if (action === 'stop_on' || action === 'stop_off') {
            form.transform(() => ({
                category_ids,
                from,
                to,
                stop_sell: action === 'stop_on',
            }));
            form.post(InventoryController.stopSell.url(), options);
        } else if (action === 'hold_back') {
            form.transform(() => ({ category_ids, from, to, rooms }));
            form.post(InventoryController.holdBack.url(), options);
        } else {
            // One override per selected category, created from the calendar.
            for (const id of category_ids) {
                router.post(
                    RateOverrideController.store.url(),
                    {
                        room_category_id: id,
                        from_date: from,
                        to_date: to,
                        rate,
                        stop_sell: false,
                        closed_to_arrival: false,
                        label: label || t('Calendar price'),
                    },
                    options,
                );
            }
        }
    };

    const toggle = (id: string) =>
        form.setData(
            'category_ids',
            form.data.category_ids.includes(id)
                ? form.data.category_ids.filter((c) => c !== id)
                : [...form.data.category_ids, id],
        );

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-3 rounded-xl border p-4"
        >
            <Heading variant="small" title={t('Change a range of nights')} />
            <div className="flex flex-wrap gap-3 text-sm">
                {props.categories.map((category) => (
                    <label
                        key={category.id}
                        className="flex items-center gap-2"
                    >
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={form.data.category_ids.includes(
                                category.id,
                            )}
                            onChange={() => toggle(category.id)}
                        />
                        {category.name}
                    </label>
                ))}
            </div>
            <InputError message={errors.category_ids} />
            <div className="grid gap-3 sm:grid-cols-4 sm:items-end">
                <div className="grid gap-1">
                    <Label htmlFor="range-from">{t('From')}</Label>
                    <Input
                        id="range-from"
                        type="date"
                        value={form.data.from}
                        onChange={(e) => form.setData('from', e.target.value)}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="range-to">{t('To (last night)')}</Label>
                    <Input
                        id="range-to"
                        type="date"
                        value={form.data.to}
                        onChange={(e) => form.setData('to', e.target.value)}
                    />
                    <InputError message={errors.to} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="range-action">{t('Action')}</Label>
                    <select
                        id="range-action"
                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                        value={form.data.action}
                        onChange={(e) =>
                            form.setData('action', e.target.value as Action)
                        }
                    >
                        <option value="stop_on">{t('Stop sale')}</option>
                        <option value="stop_off">{t('Reopen sale')}</option>
                        <option value="hold_back">
                            {t('Hold back rooms')}
                        </option>
                        {props.can.rates && (
                            <option value="rate">{t('Set a price')}</option>
                        )}
                    </select>
                </div>
                {form.data.action === 'hold_back' && (
                    <div className="grid gap-1">
                        <Label htmlFor="range-rooms">
                            {t('Rooms kept off sale')}
                        </Label>
                        <Input
                            id="range-rooms"
                            type="number"
                            min="0"
                            value={form.data.rooms}
                            onChange={(e) =>
                                form.setData('rooms', Number(e.target.value))
                            }
                        />
                    </div>
                )}
                {form.data.action === 'rate' && (
                    <>
                        <div className="grid gap-1">
                            <Label htmlFor="range-rate">
                                {t('Nightly rate (:currency)', {
                                    currency: props.currency,
                                })}
                            </Label>
                            <Input
                                id="range-rate"
                                type="number"
                                min="0"
                                step="any"
                                value={form.data.rate}
                                onChange={(e) =>
                                    form.setData('rate', e.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="range-label">{t('Label')}</Label>
                            <Input
                                id="range-label"
                                value={form.data.label}
                                onChange={(e) =>
                                    form.setData('label', e.target.value)
                                }
                            />
                        </div>
                    </>
                )}
            </div>
            <div>
                <Button disabled={form.processing}>{t('Apply')}</Button>
            </div>
        </form>
    );
}

function HoldForm({ props, onDone }: { props: Props; onDone: () => void }) {
    const form = useForm({
        room_category_id: props.categories[0]?.id ?? '',
        check_in: props.today,
        check_out: props.dates[1]?.date ?? props.today,
        rooms: 1,
        minutes: '',
        note: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            minutes: data.minutes === '' ? null : Number(data.minutes),
        }));
        form.post(InventoryController.placeHold.url(), {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-6 sm:items-end"
        >
            <div className="grid gap-1 sm:col-span-2">
                <Label htmlFor="hold-category">{t('Room category')}</Label>
                <select
                    id="hold-category"
                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                    value={form.data.room_category_id}
                    onChange={(e) =>
                        form.setData('room_category_id', e.target.value)
                    }
                >
                    {props.categories.map((category) => (
                        <option key={category.id} value={category.id}>
                            {category.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-1">
                <Label htmlFor="hold-in">{t('Check-in')}</Label>
                <Input
                    id="hold-in"
                    type="date"
                    value={form.data.check_in}
                    onChange={(e) => form.setData('check_in', e.target.value)}
                />
                <InputError message={errors.check_in} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="hold-out">{t('Check-out')}</Label>
                <Input
                    id="hold-out"
                    type="date"
                    value={form.data.check_out}
                    onChange={(e) => form.setData('check_out', e.target.value)}
                />
                <InputError message={errors.check_out} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="hold-rooms">{t('Rooms')}</Label>
                <Input
                    id="hold-rooms"
                    type="number"
                    min="1"
                    value={form.data.rooms}
                    onChange={(e) =>
                        form.setData('rooms', Number(e.target.value))
                    }
                />
                <InputError message={errors.rooms} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="hold-minutes">{t('Minutes (optional)')}</Label>
                <Input
                    id="hold-minutes"
                    type="number"
                    min="5"
                    value={form.data.minutes}
                    onChange={(e) => form.setData('minutes', e.target.value)}
                />
            </div>
            <div className="grid gap-1 sm:col-span-4">
                <Label htmlFor="hold-note">{t('Note')}</Label>
                <Input
                    id="hold-note"
                    value={form.data.note}
                    maxLength={200}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
            </div>
            <div className="flex gap-2 sm:col-span-2">
                <Button disabled={form.processing}>{t('Hold rooms')}</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}

export default function Calendar(props: Props) {
    const [holding, setHolding] = useState(false);
    const go = (start: string, days = props.days) =>
        router.get(
            CalendarController.url({ query: { start, days } }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={t('Availability')} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading
                        title={t('Availability')}
                        description={t(
                            'Rooms free to sell each night, and the nightly price.',
                        )}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label={t('Earlier')}
                            onClick={() => go(props.previous)}
                        >
                            <ChevronLeft />
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => go(props.today)}
                        >
                            {t('Today')}
                        </Button>
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label={t('Later')}
                            onClick={() => go(props.next)}
                        >
                            <ChevronRight />
                        </Button>
                        <select
                            aria-label={t('Days shown')}
                            className="flex h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                            value={props.days}
                            onChange={(e) =>
                                go(props.start, Number(e.target.value))
                            }
                        >
                            <option value={14}>{t('14 days')}</option>
                            <option value={30}>{t('30 days')}</option>
                        </select>
                    </div>
                </div>

                {props.categories.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('Add room categories first.')}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full border-collapse text-xs">
                            <thead>
                                <tr>
                                    <th className="sticky left-0 z-10 min-w-36 bg-background px-3 py-2 text-left font-medium">
                                        {t('Category')}
                                    </th>
                                    {props.dates.map((d) => (
                                        <th
                                            key={d.date}
                                            className={cn(
                                                'min-w-16 px-1 py-2 text-center font-medium',
                                                d.weekend && 'bg-muted/40',
                                                d.today && 'text-primary',
                                            )}
                                        >
                                            <div>{d.weekday}</div>
                                            <div className="text-muted-foreground">
                                                {d.day}
                                            </div>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {props.categories.map((category) => (
                                    <tr key={category.id} className="border-t">
                                        <th
                                            scope="row"
                                            className="sticky left-0 z-10 bg-background px-3 py-2 text-left font-medium"
                                        >
                                            {category.name}
                                        </th>
                                        {props.dates.map((d) => {
                                            const cell =
                                                props.cells[category.id]?.[
                                                    d.date
                                                ];

                                            if (!cell) {
                                                return <td key={d.date} />;
                                            }

                                            return (
                                                <td
                                                    key={d.date}
                                                    className="p-0.5"
                                                >
                                                    <div
                                                        className={cn(
                                                            'rounded px-1 py-1.5 text-center',
                                                            cellTone(cell),
                                                        )}
                                                        title={t(
                                                            'Free :available of :sellable · held :held · booked :booked · kept off sale :held_back',
                                                            {
                                                                available:
                                                                    cell.available,
                                                                sellable:
                                                                    cell.sellable,
                                                                held: cell.held,
                                                                booked: cell.booked,
                                                                held_back:
                                                                    cell.held_back,
                                                            },
                                                        )}
                                                    >
                                                        <div className="font-semibold">
                                                            {cell.available}/
                                                            {cell.sellable}
                                                        </div>
                                                        <div className="truncate opacity-80">
                                                            {cell.rate ?? '—'}
                                                        </div>
                                                    </div>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-xs text-muted-foreground">
                    {t(
                        'Each cell: rooms free / rooms in service, then the nightly price. Red: full. Amber: nearly full. Grey: sale stopped.',
                    )}
                </p>

                {props.can.manage && props.categories.length > 0 && (
                    <RangeActions props={props} />
                )}

                <section className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Heading
                            variant="small"
                            title={t('Rooms on hold')}
                            description={t(
                                'Held while a booking is in progress. Holds end automatically.',
                            )}
                        />
                        {props.can.manage &&
                            !holding &&
                            props.categories.length > 0 && (
                                <Button
                                    variant="outline"
                                    onClick={() => setHolding(true)}
                                >
                                    {t('Hold rooms')}
                                </Button>
                            )}
                    </div>
                    {holding && (
                        <HoldForm
                            props={props}
                            onDone={() => setHolding(false)}
                        />
                    )}
                    <ul className="divide-y rounded-xl border">
                        {props.holds.length === 0 && (
                            <li className="px-4 py-6 text-center text-sm text-muted-foreground">
                                {t('No rooms on hold.')}
                            </li>
                        )}
                        {props.holds.map((hold) => (
                            <li
                                key={hold.id}
                                className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">
                                        {hold.category} ·{' '}
                                        {t(':count room(s)', {
                                            count: hold.rooms,
                                        })}
                                    </div>
                                    <div className="text-muted-foreground">
                                        {hold.check_in} → {hold.check_out} ·{' '}
                                        {t('until :time', {
                                            time: hold.expires_at,
                                        })}
                                        {hold.note && ` · ${hold.note}`}
                                    </div>
                                </div>
                                {props.can.manage && (
                                    <div className="flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    InventoryController.extendHold.url(
                                                        hold.id,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Extend')}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive"
                                            onClick={() =>
                                                router.delete(
                                                    InventoryController.releaseHold.url(
                                                        hold.id,
                                                    ),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Release')}
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                {props.categories.length > 0 && (
                    <TryAStay
                        categories={props.categories}
                        quote={props.quote}
                    />
                )}
            </div>
        </>
    );
}

Calendar.layout = {
    breadcrumbs: [{ title: 'Availability', href: CalendarController() }],
};
