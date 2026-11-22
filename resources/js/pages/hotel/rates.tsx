import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import CategoryRateController from '@/actions/App/Domains/Rates/Http/Controllers/CategoryRateController';
import RateOverrideController from '@/actions/App/Domains/Rates/Http/Controllers/RateOverrideController';
import RatesController from '@/actions/App/Domains/Rates/Http/Controllers/RatesController';
import RateSettingsController from '@/actions/App/Domains/Rates/Http/Controllers/RateSettingsController';
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
    Tabs,
    useTabs,
} from '@/components/page';
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

const selectClass = 'h-9 w-full disabled:opacity-50';

const weekdayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function today(offset = 0): string {
    const date = new Date();
    date.setDate(date.getDate() + offset);

    return date.toISOString().slice(0, 10);
}

function CategoryRateForm({
    category,
    props,
    onDone,
}: {
    category: Category;
    props: Props;
    onDone: () => void;
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
            onSuccess: onDone,
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
        <form onSubmit={submit} className="grid gap-6">
            <fieldset disabled={!props.canManage} className="grid gap-6">
                <FieldGroup
                    title={t('Room price')}
                    description={t('Per night, in :currency.', {
                        currency: props.currency,
                    })}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field(
                            'base',
                            t('Nightly rate (:guests guests)', {
                                guests: category.standard_occupancy,
                            }),
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
                                    form.setData(
                                        'min_nights',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <InputError message={errors.min_nights} />
                        </div>
                    </div>
                </FieldGroup>
                <FieldGroup
                    title={t('Extra people')}
                    description={
                        category.extra_bed_allowed
                            ? undefined
                            : t('Extra beds are off for this category.')
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-3">
                        {field('extra_adult', t('Extra adult per night'))}
                        {field('child', t('Child per night'))}
                        {field('extra_bed', t('Extra bed per night'))}
                    </div>
                </FieldGroup>
                <FieldGroup title={t('Breakfast')}>
                    <div className="grid gap-4 sm:grid-cols-2">
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
                                    form.setData(
                                        'breakfast_mode',
                                        e.target.value,
                                    )
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
                                        form.setData(
                                            'breakfast',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={errors.breakfast} />
                            </div>
                        )}
                    </div>
                </FieldGroup>
            </fieldset>
            {props.canManage && (
                <div className="flex justify-end gap-2 border-t pt-4">
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('Cancel')}
                    </Button>
                    <Button disabled={form.processing}>{t('Save rate')}</Button>
                </div>
            )}
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

    return (
        <form onSubmit={submit} className="grid gap-6">
            <div className="grid gap-4 sm:grid-cols-4">
                <div className="grid gap-1 sm:col-span-4">
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
                <div className="grid gap-1 sm:col-span-4">
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
                <div className="grid gap-1 sm:col-span-2">
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
                <div className="grid gap-1 sm:col-span-2">
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
                <div className="grid gap-1 sm:col-span-2">
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
                <div className="grid gap-1 sm:col-span-2">
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
            <Field label={t('Only on:')} hint={t('(none ticked = every day)')}>
                <ChipSelect
                    label={t('Only on:')}
                    value={form.data.weekdays.map(String)}
                    onChange={(v) =>
                        form.setData(
                            'weekdays',
                            v.map(Number).sort((x, y) => x - y),
                        )
                    }
                    options={weekdayNames.map((name, index) => ({
                        value: String(index + 1),
                        label: t(name),
                    }))}
                />
            </Field>
            <div className="grid gap-3">
                <Switch
                    checked={form.data.stop_sell}
                    onChange={(v) => form.setData('stop_sell', v)}
                    label={t('Stop sale')}
                    hint={t('No booking at all on these dates.')}
                />
                <Switch
                    checked={form.data.closed_to_arrival}
                    onChange={(v) => form.setData('closed_to_arrival', v)}
                    label={t('No arrivals')}
                    hint={t(
                        'Guests already staying can continue; nobody can check in on these dates.',
                    )}
                />
            </div>
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button disabled={form.processing}>{t('Save override')}</Button>
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
        <form onSubmit={submit} className="flex flex-col gap-6">
            <fieldset disabled={!props.canManage} className="contents">
                <Section
                    aside
                    title={t('Child age bands')}
                    description={t(
                        'Older children are charged as adults. With no bands, every child pays the child rate.',
                    )}
                >
                    <div className="grid gap-3">
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
                                        setBand(index, {
                                            from: Number(e.target.value),
                                        })
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
                                        setBand(index, {
                                            to: Number(e.target.value),
                                        })
                                    }
                                />
                                <select
                                    className={`${selectClass} h-8 w-auto`}
                                    aria-label={t('Charge')}
                                    value={band.charge}
                                    onChange={(e) =>
                                        setBand(index, {
                                            charge: e.target.value,
                                        })
                                    }
                                >
                                    {props.childCharges.map((charge) => (
                                        <option
                                            key={charge.value}
                                            value={charge.value}
                                        >
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
                    </div>
                </Section>
                <Section
                    aside
                    title={t('Rooms on hold')}
                    description={t(
                        'While a guest is booking on WhatsApp, the rooms are kept for them this long.',
                    )}
                >
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
                                form.setData(
                                    'hold_minutes',
                                    Number(e.target.value),
                                )
                            }
                        />
                        <InputError message={errors.hold_minutes} />
                    </div>
                </Section>
                {props.canManage && (
                    <div className="flex justify-end">
                        <Button disabled={form.processing}>
                            {t('Save pricing settings')}
                        </Button>
                    </div>
                )}
            </fieldset>
        </form>
    );
}

const amount = (value: string | null | undefined) =>
    value === null || value === undefined || value === ''
        ? '—'
        : Number(value).toLocaleString();

export default function Rates(props: Props) {
    const tabs = [
        {
            key: 'rooms',
            label: t('Room rates'),
            count: props.categories.length,
        },
        {
            key: 'dates',
            label: t('Date overrides'),
            count: props.overrides.length,
        },
        { key: 'settings', label: t('Pricing settings') },
    ];
    const [tab, setTab] = useTabs(tabs);
    const [category, setCategory] = useState<Category | null>(null);
    const [editing, setEditing] = useState<Override | 'new' | null>(null);
    const override =
        editing === 'new' || editing === null ? undefined : editing;
    const categoryName = (id: string | null) =>
        id === null
            ? t('All categories')
            : (props.categories.find((c) => c.id === id)?.name ?? '—');
    const breakfastLabel = (mode: string) =>
        props.breakfastModes.find((m) => m.value === mode)?.label ?? mode;

    return (
        <>
            <Head title={t('Rates')} />
            <PageBody>
                <PageHeader
                    title={t('Rates')}
                    description={t(
                        'Prices in :currency. Taxes are applied from your tax settings.',
                        { currency: props.currency },
                    )}
                    actions={
                        tab === 'dates' &&
                        props.canManage && (
                            <Button onClick={() => setEditing('new')}>
                                <Plus /> {t('Add override')}
                            </Button>
                        )
                    }
                />
                <Tabs tabs={tabs} active={tab} onSelect={setTab} />

                {tab === 'rooms' && (
                    <>
                        <Section
                            flush
                            title={t('Room rates')}
                            description={t(
                                'The usual price of each room category. Click a category to change it.',
                            )}
                        >
                            <DataTable
                                rows={props.categories}
                                rowKey={(c) => c.id}
                                empty={t('Add room categories first.')}
                                onRowClick={setCategory}
                                columns={[
                                    {
                                        key: 'name',
                                        header: t('Room category'),
                                        cell: (c) => (
                                            <div>
                                                <div className="font-medium">
                                                    {c.name}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {c.code} ·{' '}
                                                    {t(':count guests', {
                                                        count: c.standard_occupancy,
                                                    })}
                                                </div>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'base',
                                        header: t('Per night'),
                                        align: 'right',
                                        cell: (c) =>
                                            c.rate ? (
                                                <span className="font-semibold">
                                                    {amount(c.rate.base)}{' '}
                                                    <span className="text-xs font-normal text-muted-foreground">
                                                        {props.currency}
                                                    </span>
                                                </span>
                                            ) : (
                                                <Badge variant="destructive">
                                                    {t('No rate yet')}
                                                </Badge>
                                            ),
                                    },
                                    {
                                        key: 'extra',
                                        header: t('Extra adult / child'),
                                        align: 'right',
                                        cell: (c) =>
                                            c.rate
                                                ? `${amount(c.rate.extra_adult)} / ${amount(c.rate.child)}`
                                                : '—',
                                    },
                                    {
                                        key: 'breakfast',
                                        header: t('Breakfast'),
                                        cell: (c) =>
                                            c.rate
                                                ? breakfastLabel(
                                                      c.rate.breakfast_mode,
                                                  )
                                                : '—',
                                    },
                                    {
                                        key: 'min',
                                        header: t('Minimum nights'),
                                        align: 'right',
                                        cell: (c) => c.rate?.min_nights ?? '—',
                                    },
                                    {
                                        key: 'status',
                                        header: t('Status'),
                                        cell: (c) =>
                                            c.active ? (
                                                <Badge variant="outline">
                                                    {t('On sale')}
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary">
                                                    {t('Not on sale')}
                                                </Badge>
                                            ),
                                    },
                                ]}
                            />
                        </Section>
                        {props.categories.length > 0 && (
                            <TryAStay
                                categories={props.categories}
                                quote={props.quote}
                            />
                        )}
                    </>
                )}

                {tab === 'dates' && (
                    <Section
                        flush
                        title={t('Date overrides')}
                        description={t(
                            'Seasons, events and restrictions. When overrides overlap, the most recently changed one sets the price.',
                        )}
                    >
                        <DataTable
                            rows={props.overrides}
                            rowKey={(o) => o.id}
                            empty={t(
                                'No override yet: the usual rates apply every day.',
                            )}
                            onRowClick={
                                props.canManage ? setEditing : undefined
                            }
                            columns={[
                                {
                                    key: 'label',
                                    header: t('Label'),
                                    cell: (o) => (
                                        <div>
                                            <div className="font-medium">
                                                {o.label}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {categoryName(
                                                    o.room_category_id,
                                                )}
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'dates',
                                    header: t('Dates'),
                                    cell: (o) => (
                                        <div>
                                            <div className="tabular-nums">
                                                {o.from_date} → {o.to_date}
                                            </div>
                                            {o.weekdays.length > 0 && (
                                                <div className="text-xs text-muted-foreground">
                                                    {o.weekdays
                                                        .map((d) =>
                                                            t(
                                                                weekdayNames[
                                                                    d - 1
                                                                ],
                                                            ),
                                                        )
                                                        .join(', ')}
                                                </div>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'rate',
                                    header: t('Per night'),
                                    align: 'right',
                                    cell: (o) =>
                                        o.rate !== null
                                            ? `${amount(o.rate)} ${props.currency}`
                                            : '—',
                                },
                                {
                                    key: 'rules',
                                    header: t('Rules'),
                                    cell: (o) => (
                                        <div className="flex flex-wrap gap-1">
                                            {o.min_nights !== null && (
                                                <Badge variant="outline">
                                                    {t('min :nights nights', {
                                                        nights: o.min_nights,
                                                    })}
                                                </Badge>
                                            )}
                                            {o.stop_sell && (
                                                <Badge variant="destructive">
                                                    {t('Stop sale')}
                                                </Badge>
                                            )}
                                            {o.closed_to_arrival && (
                                                <Badge variant="secondary">
                                                    {t('No arrivals')}
                                                </Badge>
                                            )}
                                            {o.overlaps && (
                                                <Badge variant="outline">
                                                    {t(
                                                        'Overlaps another override',
                                                    )}
                                                </Badge>
                                            )}
                                        </div>
                                    ),
                                },
                                ...(props.canManage
                                    ? [
                                          {
                                              key: 'delete',
                                              header: '',
                                              align: 'right' as const,
                                              cell: (o: Override) => (
                                                  <Button
                                                      variant="ghost"
                                                      size="sm"
                                                      className="text-destructive"
                                                      onClick={(e) => {
                                                          e.stopPropagation();
                                                          if (
                                                              window.confirm(
                                                                  t(
                                                                      'Delete :name?',
                                                                      {
                                                                          name: o.label,
                                                                      },
                                                                  ),
                                                              )
                                                          ) {
                                                              router.delete(
                                                                  RateOverrideController.destroy.url(
                                                                      o.id,
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
                                              ),
                                          },
                                      ]
                                    : []),
                            ]}
                        />
                    </Section>
                )}

                {tab === 'settings' && <SettingsForm props={props} />}
            </PageBody>

            <SidePanel
                open={category !== null}
                onClose={() => setCategory(null)}
                title={category ? category.name : ''}
                description={
                    category
                        ? t('Nightly rate (:guests guests)', {
                              guests: category.standard_occupancy,
                          })
                        : undefined
                }
            >
                {category && (
                    <CategoryRateForm
                        key={category.id}
                        category={category}
                        props={props}
                        onDone={() => setCategory(null)}
                    />
                )}
            </SidePanel>
            <SidePanel
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={override ? override.label : t('Add override')}
                description={t(
                    'A different price or rule for some dates (a season, an event, a closure).',
                )}
            >
                {editing !== null && (
                    <OverrideForm
                        key={override?.id ?? 'new'}
                        props={props}
                        override={override}
                        onDone={() => setEditing(null)}
                    />
                )}
            </SidePanel>
        </>
    );
}

Rates.layout = {
    breadcrumbs: [{ title: 'Rates', href: RatesController() }],
};
