import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import CategoryRateController from '@/actions/App/Domains/Rates/Http/Controllers/CategoryRateController';
import RateOverrideController from '@/actions/App/Domains/Rates/Http/Controllers/RateOverrideController';
import RatesController from '@/actions/App/Domains/Rates/Http/Controllers/RatesController';
import RateSettingsController from '@/actions/App/Domains/Rates/Http/Controllers/RateSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { TryAStay } from '@/components/rates/try-a-stay';
import type { QuoteResult } from '@/components/rates/try-a-stay';

type Option = { value: string; label: string };

type Rate = {
    base: string;
    extra_adult: string;
    child: string;
    extra_bed: string;
    breakfast_mode: string;
    breakfast: string;
    min_nights: number;
};

type Category = {
    id: string;
    code: string;
    name: string;
    active: boolean;
    extra_bed_allowed: boolean;
    standard_occupancy: number;
    rate: Rate | null;
};

type Override = {
    id: string;
    room_category_id: string | null;
    from_date: string;
    to_date: string;
    weekdays: number[];
    rate: string | null;
    min_nights: number | null;
    stop_sell: boolean;
    closed_to_arrival: boolean;
    label: string;
    overlaps: boolean;
};

type Band = { from: number; to: number; charge: string };

type Props = {
    currency: string;
    categories: Category[];
    overrides: Override[];
    settings: { child_age_bands: Band[]; hold_minutes: number };
    breakfastModes: Option[];
    childCharges: Option[];
    quote?: QuoteResult;
    canManage: boolean;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50';

const weekdayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function today(offset = 0): string {
    const date = new Date();
    date.setDate(date.getDate() + offset);

    return date.toISOString().slice(0, 10);
}

function CategoryRateForm({
    category,
    props,
}: {
    category: Category;
    props: Props;
}) {
    const form = useForm({
        base: category.rate?.base ?? '',
        extra_adult: category.rate?.extra_adult ?? '0',
        child: category.rate?.child ?? '0',
        extra_bed: category.rate?.extra_bed ?? '0',
        breakfast_mode: category.rate?.breakfast_mode ?? 'none',
        breakfast: category.rate?.breakfast ?? '0',
        min_nights: category.rate?.min_nights ?? 1,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(CategoryRateController.update.url(category.id), {
            preserveScroll: true,
        });
    };

    const field = (
        key: 'base' | 'extra_adult' | 'child' | 'extra_bed',
        label: string,
    ) => (
        <div className="grid gap-1">
            <Label htmlFor={`${category.id}-${key}`} className="text-xs">
                {label}
            </Label>
            <Input
                id={`${category.id}-${key}`}
                type="number"
                min="0"
                step="any"
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
                disabled={key === 'extra_bed' && !category.extra_bed_allowed}
            />
            <InputError message={errors[key]} />
        </div>
    );

    return (
        <form onSubmit={submit} className="rounded-xl border p-4">
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <span className="font-medium">{category.name}</span>
                <span className="text-sm text-muted-foreground">
                    {category.code}
                </span>
                {!category.active && (
                    <Badge variant="secondary">{t('Not on sale')}</Badge>
                )}
                {category.rate === null && (
                    <Badge variant="destructive">{t('No rate yet')}</Badge>
                )}
            </div>
            <fieldset
                disabled={!props.canManage}
                className="grid gap-3 sm:grid-cols-4"
            >
                {field(
                    'base',
                    t('Nightly rate (:guests guests)', {
                        guests: category.standard_occupancy,
                    }),
                )}
                {field('extra_adult', t('Extra adult per night'))}
                {field('child', t('Child per night'))}
                {field('extra_bed', t('Extra bed per night'))}
                <div className="grid gap-1">
                    <Label
                        htmlFor={`${category.id}-breakfast_mode`}
                        className="text-xs"
                    >
                        {t('Breakfast')}
                    </Label>
                    <select
                        id={`${category.id}-breakfast_mode`}
                        className={selectClass}
                        value={form.data.breakfast_mode}
                        onChange={(e) =>
                            form.setData('breakfast_mode', e.target.value)
                        }
                    >
                        {props.breakfastModes.map((mode) => (
                            <option key={mode.value} value={mode.value}>
                                {mode.label}
                            </option>
                        ))}
                    </select>
                </div>
                {form.data.breakfast_mode === 'optional' && (
                    <div className="grid gap-1">
                        <Label
                            htmlFor={`${category.id}-breakfast`}
                            className="text-xs"
                        >
                            {t('Breakfast per person per night')}
                        </Label>
                        <Input
                            id={`${category.id}-breakfast`}
                            type="number"
                            min="0"
                            step="any"
                            value={form.data.breakfast}
                            onChange={(e) =>
                                form.setData('breakfast', e.target.value)
                            }
                        />
                        <InputError message={errors.breakfast} />
                    </div>
                )}
                <div className="grid gap-1">
                    <Label
                        htmlFor={`${category.id}-min_nights`}
                        className="text-xs"
                    >
                        {t('Minimum nights')}
                    </Label>
                    <Input
                        id={`${category.id}-min_nights`}
                        type="number"
                        min="1"
                        max="30"
                        value={form.data.min_nights}
                        onChange={(e) =>
                            form.setData('min_nights', Number(e.target.value))
                        }
                    />
                    <InputError message={errors.min_nights} />
                </div>
                {props.canManage && (
                    <div className="flex items-end">
                        <Button disabled={form.processing}>
                            {t('Save rate')}
                        </Button>
                    </div>
                )}
            </fieldset>
        </form>
    );
}

function OverrideForm({
    props,
    override,
    onDone,
}: {
    props: Props;
    override?: Override;
    onDone: () => void;
}) {
    const form = useForm({
        room_category_id: override?.room_category_id ?? '',
        from_date: override?.from_date ?? today(),
        to_date: override?.to_date ?? today(7),
        weekdays: override?.weekdays ?? ([] as number[]),
        rate: override?.rate ?? '',
        min_nights: override?.min_nights ?? '',
        stop_sell: override?.stop_sell ?? false,
        closed_to_arrival: override?.closed_to_arrival ?? false,
        label: override?.label ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            room_category_id: data.room_category_id || null,
            rate: data.rate === '' ? null : data.rate,
            min_nights: data.min_nights === '' ? null : data.min_nights,
        }));
        const options = { preserveScroll: true, onSuccess: () => onDone() };

        if (override) {
            form.put(RateOverrideController.update.url(override.id), options);
        } else {
            form.post(RateOverrideController.store.url(), options);
        }
    };

    const toggleDay = (day: number) =>
        form.setData(
            'weekdays',
            form.data.weekdays.includes(day)
                ? form.data.weekdays.filter((d) => d !== day)
                : [...form.data.weekdays, day],
        );

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-4 rounded-xl border p-4"
        >
            <div className="grid gap-3 sm:grid-cols-4">
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor="override-label">{t('Label')}</Label>
                    <Input
                        id="override-label"
                        value={form.data.label}
                        onChange={(e) => form.setData('label', e.target.value)}
                        placeholder={t('For example: Festival week')}
                        required
                    />
                    <InputError message={errors.label} />
                </div>
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor="override-category">
                        {t('Room category')}
                    </Label>
                    <select
                        id="override-category"
                        className={selectClass}
                        value={form.data.room_category_id}
                        onChange={(e) =>
                            form.setData('room_category_id', e.target.value)
                        }
                    >
                        <option value="">{t('All categories')}</option>
                        {props.categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="override-from">{t('From')}</Label>
                    <Input
                        id="override-from"
                        type="date"
                        value={form.data.from_date}
                        onChange={(e) =>
                            form.setData('from_date', e.target.value)
                        }
                        required
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="override-to">{t('To (last night)')}</Label>
                    <Input
                        id="override-to"
                        type="date"
                        value={form.data.to_date}
                        onChange={(e) =>
                            form.setData('to_date', e.target.value)
                        }
                        required
                    />
                    <InputError message={errors.to_date} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="override-rate">
                        {t('Nightly rate (optional)')}
                    </Label>
                    <Input
                        id="override-rate"
                        type="number"
                        min="0"
                        step="any"
                        value={form.data.rate}
                        onChange={(e) => form.setData('rate', e.target.value)}
                    />
                    <InputError message={errors.rate} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="override-min">
                        {t('Minimum nights (optional)')}
                    </Label>
                    <Input
                        id="override-min"
                        type="number"
                        min="1"
                        max="30"
                        value={form.data.min_nights}
                        onChange={(e) =>
                            form.setData(
                                'min_nights',
                                e.target.value === ''
                                    ? ''
                                    : Number(e.target.value),
                            )
                        }
                    />
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <span className="text-muted-foreground">{t('Only on:')}</span>
                {weekdayNames.map((name, index) => (
                    <label key={name} className="flex items-center gap-1">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={form.data.weekdays.includes(index + 1)}
                            onChange={() => toggleDay(index + 1)}
                        />
                        {t(name)}
                    </label>
                ))}
                <span className="text-muted-foreground">
                    {t('(none ticked = every day)')}
                </span>
            </div>
            <div className="flex flex-wrap gap-4 text-sm">
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.stop_sell}
                        onChange={(e) =>
                            form.setData('stop_sell', e.target.checked)
                        }
                    />
                    {t('Stop sale')}
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.closed_to_arrival}
                        onChange={(e) =>
                            form.setData('closed_to_arrival', e.target.checked)
                        }
                    />
                    {t('No arrivals')}
                </label>
            </div>
            <div className="flex gap-2">
                <Button disabled={form.processing}>{t('Save override')}</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}

function SettingsForm({ props }: { props: Props }) {
    const form = useForm({
        child_age_bands: props.settings.child_age_bands,
        hold_minutes: props.settings.hold_minutes,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const bands = form.data.child_age_bands;

    const setBand = (index: number, band: Partial<Band>) =>
        form.setData(
            'child_age_bands',
            bands.map((b, i) => (i === index ? { ...b, ...band } : b)),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(RateSettingsController.update.url(), { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-4 rounded-xl border p-4"
        >
            <fieldset
                disabled={!props.canManage}
                className="flex flex-col gap-3"
            >
                <Label>{t('Child age bands')}</Label>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Older children are charged as adults. With no bands, every child pays the child rate.',
                    )}
                </p>
                {bands.map((band, index) => (
                    <div
                        key={index}
                        className="flex flex-wrap items-center gap-2 text-sm"
                    >
                        <Input
                            type="number"
                            min="0"
                            max="17"
                            className="h-8 w-20"
                            aria-label={t('From age')}
                            value={band.from}
                            onChange={(e) =>
                                setBand(index, { from: Number(e.target.value) })
                            }
                        />
                        –
                        <Input
                            type="number"
                            min="0"
                            max="17"
                            className="h-8 w-20"
                            aria-label={t('To age')}
                            value={band.to}
                            onChange={(e) =>
                                setBand(index, { to: Number(e.target.value) })
                            }
                        />
                        <select
                            className={`${selectClass} h-8 w-auto`}
                            aria-label={t('Charge')}
                            value={band.charge}
                            onChange={(e) =>
                                setBand(index, { charge: e.target.value })
                            }
                        >
                            {props.childCharges.map((charge) => (
                                <option key={charge.value} value={charge.value}>
                                    {charge.label}
                                </option>
                            ))}
                        </select>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                form.setData(
                                    'child_age_bands',
                                    bands.filter((_, i) => i !== index),
                                )
                            }
                        >
                            {t('Remove')}
                        </Button>
                    </div>
                ))}
                <InputError
                    message={
                        errors['child_age_bands.0.from'] ??
                        errors['child_age_bands.1.from'] ??
                        errors['child_age_bands.2.from']
                    }
                />
                {bands.length < 3 && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-fit"
                        onClick={() => {
                            const from =
                                bands.length === 0
                                    ? 0
                                    : bands[bands.length - 1].to + 1;
                            form.setData('child_age_bands', [
                                ...bands,
                                {
                                    from,
                                    to: Math.min(17, from + 5),
                                    charge: 'child',
                                },
                            ]);
                        }}
                    >
                        {t('Add age band')}
                    </Button>
                )}
                <div className="grid max-w-xs gap-1">
                    <Label htmlFor="hold_minutes">
                        {t(
                            'Hold rooms while a booking is in progress (minutes)',
                        )}
                    </Label>
                    <Input
                        id="hold_minutes"
                        type="number"
                        min="5"
                        max="1440"
                        value={form.data.hold_minutes}
                        onChange={(e) =>
                            form.setData('hold_minutes', Number(e.target.value))
                        }
                    />
                    <InputError message={errors.hold_minutes} />
                </div>
                {props.canManage && (
                    <div>
                        <Button disabled={form.processing}>
                            {t('Save pricing settings')}
                        </Button>
                    </div>
                )}
            </fieldset>
        </form>
    );
}

export default function Rates(props: Props) {
    const [editing, setEditing] = useState<string | null>(null);
    const categoryName = (id: string | null) =>
        id === null
            ? t('All categories')
            : (props.categories.find((c) => c.id === id)?.name ?? '—');

    return (
        <>
            <Head title={t('Rates')} />
            <div className="flex max-w-5xl flex-col gap-8 p-4">
                <Heading
                    title={t('Rates')}
                    description={t(
                        'Prices in :currency. Taxes are applied from your tax settings.',
                        { currency: props.currency },
                    )}
                />

                <section className="flex flex-col gap-3">
                    {props.categories.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('Add room categories first.')}
                        </p>
                    )}
                    {props.categories.map((category) => (
                        <CategoryRateForm
                            key={category.id}
                            category={category}
                            props={props}
                        />
                    ))}
                </section>

                <section className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Heading
                            variant="small"
                            title={t('Date overrides')}
                            description={t(
                                'Seasons, events and restrictions. When overrides overlap, the most recently changed one sets the price.',
                            )}
                        />
                        {props.canManage && editing === null && (
                            <Button
                                variant="outline"
                                onClick={() => setEditing('new')}
                            >
                                {t('Add override')}
                            </Button>
                        )}
                    </div>
                    {editing === 'new' && (
                        <OverrideForm
                            props={props}
                            onDone={() => setEditing(null)}
                        />
                    )}
                    <ul className="flex flex-col gap-2">
                        {props.overrides.map((override) =>
                            editing === override.id ? (
                                <li key={override.id}>
                                    <OverrideForm
                                        props={props}
                                        override={override}
                                        onDone={() => setEditing(null)}
                                    />
                                </li>
                            ) : (
                                <li
                                    key={override.id}
                                    className="flex flex-wrap items-center gap-2 rounded-xl border px-4 py-3 text-sm"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="font-medium">
                                            {override.label}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {categoryName(
                                                override.room_category_id,
                                            )}{' '}
                                            · {override.from_date} →{' '}
                                            {override.to_date}
                                            {override.weekdays.length > 0 &&
                                                ` · ${override.weekdays.map((d) => t(weekdayNames[d - 1])).join(', ')}`}
                                            {override.rate !== null &&
                                                ` · ${override.rate} ${props.currency}`}
                                            {override.min_nights !== null &&
                                                ` · ${t('min :nights nights', { nights: override.min_nights })}`}
                                        </div>
                                    </div>
                                    {override.stop_sell && (
                                        <Badge variant="destructive">
                                            {t('Stop sale')}
                                        </Badge>
                                    )}
                                    {override.closed_to_arrival && (
                                        <Badge variant="secondary">
                                            {t('No arrivals')}
                                        </Badge>
                                    )}
                                    {override.overlaps && (
                                        <Badge variant="outline">
                                            {t('Overlaps another override')}
                                        </Badge>
                                    )}
                                    {props.canManage && (
                                        <div className="flex gap-1">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing(override.id)
                                                }
                                            >
                                                {t('Edit')}
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive"
                                                onClick={() => {
                                                    if (
                                                        window.confirm(
                                                            t('Delete :name?', {
                                                                name: override.label,
                                                            }),
                                                        )
                                                    ) {
                                                        router.delete(
                                                            RateOverrideController.destroy.url(
                                                                override.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }
                                                }}
                                            >
                                                {t('Delete')}
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ),
                        )}
                    </ul>
                </section>

                <section className="flex flex-col gap-3">
                    <Heading variant="small" title={t('Pricing settings')} />
                    <SettingsForm props={props} />
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

Rates.layout = {
    breadcrumbs: [{ title: 'Rates', href: RatesController() }],
};
