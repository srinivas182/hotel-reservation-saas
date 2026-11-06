import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import MenuController from '@/actions/App/Domains/Restaurant/Http/Controllers/MenuController';
import RestaurantController from '@/actions/App/Domains/Restaurant/Http/Controllers/RestaurantController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type Option = { value: string; label: string };
type Item = {
    id: number;
    name: Record<string, string>;
    description: Record<string, string>;
    price: number;
    price_to_pay: string;
    labels: string[];
    allergens: string[];
    period_names: string;
    photo_url: string | null;
    available_today: boolean;
    active: boolean;
};
type Section = { id: number; name: Record<string, string>; items: Item[] };
type Props = {
    outlets: { id: string; name: string }[];
    outlet: {
        id: string;
        name: string;
        public_url: string;
        qr_url: string;
    } | null;
    sections: Section[];
    labels: Option[];
    allergens: Option[];
    locales: string[];
    currency: string;
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const empty = (locales: string[]) =>
    Object.fromEntries(locales.map((l) => [l, ''])) as Record<string, string>;

function Checks({
    options,
    value,
    onChange,
}: {
    options: Option[];
    value: string[];
    onChange: (v: string[]) => void;
}) {
    return (
        <div className="flex flex-wrap gap-x-4 gap-y-1">
            {options.map((o) => (
                <label
                    key={o.value}
                    className="flex items-center gap-1 text-xs"
                >
                    <input
                        type="checkbox"
                        className="size-3.5"
                        checked={value.includes(o.value)}
                        onChange={() =>
                            onChange(
                                value.includes(o.value)
                                    ? value.filter((v) => v !== o.value)
                                    : [...value, o.value],
                            )
                        }
                    />
                    {o.label}
                </label>
            ))}
        </div>
    );
}

function ItemForm({
    sectionId,
    item,
    locales,
    labels,
    allergens,
    currency,
    onDone,
}: {
    sectionId: number;
    item?: Item;
    locales: string[];
    labels: Option[];
    allergens: Option[];
    currency: string;
    onDone: () => void;
}) {
    const form = useForm({
        menu_section_id: sectionId,
        name: item?.name ?? empty(locales),
        description: item?.description ?? empty(locales),
        price: item?.price ?? 0,
        labels: item?.labels ?? [],
        allergens: item?.allergens ?? [],
        period_names: item?.period_names ?? '',
        active: item?.active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (item) {
            form.put(MenuController.updateItem.url(item.id), options);
        } else {
            form.post(MenuController.storeItem.url(), options);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-2 rounded-lg border p-3 sm:grid-cols-2"
        >
            {locales.map((l) => (
                <div key={l} className="grid gap-1">
                    <Label htmlFor={`dish-${item?.id ?? 'new'}-${l}`}>
                        {t('Dish (:language)', { language: l.toUpperCase() })}
                    </Label>
                    <Input
                        id={`dish-${item?.id ?? 'new'}-${l}`}
                        value={form.data.name[l] ?? ''}
                        onChange={(e) =>
                            form.setData('name', {
                                ...form.data.name,
                                [l]: e.target.value,
                            })
                        }
                    />
                    <Input
                        aria-label={t('Description (:language)', {
                            language: l.toUpperCase(),
                        })}
                        placeholder={t('Description (optional)')}
                        value={form.data.description[l] ?? ''}
                        onChange={(e) =>
                            form.setData('description', {
                                ...form.data.description,
                                [l]: e.target.value,
                            })
                        }
                    />
                </div>
            ))}
            <div className="grid gap-1">
                <Label htmlFor={`price-${item?.id ?? 'new'}`}>
                    {t('Price (:currency)', { currency })}
                </Label>
                <Input
                    id={`price-${item?.id ?? 'new'}`}
                    type="number"
                    min={0}
                    step="any"
                    value={form.data.price}
                    onChange={(e) =>
                        form.setData('price', Number(e.target.value))
                    }
                />
                <InputError message={errors.price} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`periods-${item?.id ?? 'new'}`}>
                    {t('Served at (service names, empty for all)')}
                </Label>
                <Input
                    id={`periods-${item?.id ?? 'new'}`}
                    placeholder={t('e.g. Dinner, Lunch')}
                    value={form.data.period_names}
                    onChange={(e) =>
                        form.setData('period_names', e.target.value)
                    }
                />
            </div>
            <div className="grid gap-1 sm:col-span-2">
                <span className="text-sm font-medium">{t('Labels')}</span>
                <Checks
                    options={labels}
                    value={form.data.labels}
                    onChange={(v) => form.setData('labels', v)}
                />
            </div>
            <div className="grid gap-1 sm:col-span-2">
                <span className="text-sm font-medium">
                    {t('Allergens in this dish')}
                </span>
                <Checks
                    options={allergens}
                    value={form.data.allergens}
                    onChange={(v) => form.setData('allergens', v)}
                />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.active}
                    onChange={(e) => form.setData('active', e.target.checked)}
                />
                {t('On the menu')}
            </label>
            <div className="flex gap-2">
                <Button size="sm" disabled={form.processing}>
                    {t('Save')}
                </Button>
                <Button
                    size="sm"
                    type="button"
                    variant="ghost"
                    onClick={onDone}
                >
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}

function ItemRow({
    item,
    sectionId,
    locales,
    labels,
    allergens,
    currency,
}: {
    item: Item;
    sectionId: number;
    locales: string[];
    labels: Option[];
    allergens: Option[];
    currency: string;
}) {
    const [editing, setEditing] = useState(false);

    if (editing) {
        return (
            <ItemForm
                sectionId={sectionId}
                item={item}
                locales={locales}
                labels={labels}
                allergens={allergens}
                currency={currency}
                onDone={() => setEditing(false)}
            />
        );
    }

    return (
        <li className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
            {item.photo_url ? (
                <img
                    src={item.photo_url}
                    alt=""
                    className="size-12 rounded object-cover"
                />
            ) : (
                <div className="size-12 rounded bg-muted" />
            )}
            <div className="min-w-48 flex-1">
                <div
                    className={
                        item.active
                            ? 'font-medium'
                            : 'font-medium line-through opacity-60'
                    }
                >
                    {Object.values(item.name)[0]}
                </div>
                <div className="text-xs text-muted-foreground">
                    {t('Guest pays :price', { price: item.price_to_pay })}
                    {item.period_names && ` · ${item.period_names}`}
                </div>
            </div>
            <label className="flex items-center gap-2 text-xs">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={item.available_today}
                    onChange={(e) =>
                        router.put(
                            MenuController.today.url(item.id),
                            { available: e.target.checked },
                            { preserveScroll: true },
                        )
                    }
                />
                {item.available_today ? (
                    t('Available today')
                ) : (
                    <Badge variant="destructive">
                        {t('Not available today')}
                    </Badge>
                )}
            </label>
            <label className="cursor-pointer text-xs underline">
                {item.photo_url ? t('Change photo') : t('Add photo')}
                <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="hidden"
                    onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (file) {
                            router.post(
                                MenuController.photo.url(item.id),
                                { photo: file },
                                { forceFormData: true, preserveScroll: true },
                            );
                        }
                    }}
                />
            </label>
            <Button
                size="sm"
                variant="outline"
                onClick={() => setEditing(true)}
            >
                {t('Edit')}
            </Button>
            <Button
                size="sm"
                variant="ghost"
                className="text-destructive"
                onClick={() =>
                    window.confirm(t('Remove this dish from the menu?')) &&
                    router.delete(MenuController.destroyItem.url(item.id), {
                        preserveScroll: true,
                    })
                }
            >
                {t('Remove')}
            </Button>
        </li>
    );
}

export default function RestaurantMenu({
    outlets,
    outlet,
    sections,
    labels,
    allergens,
    locales,
    currency,
}: Props) {
    const [adding, setAdding] = useState<number | null>(null);
    const sectionForm = useForm({
        outlet_id: outlet?.id ?? '',
        name: empty(locales),
    });

    return (
        <>
            <Head title={t('Menu')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title={t('Menu')}
                        description={t(
                            'Dishes, prices and what is available today. "Not available today" resets by itself the next morning.',
                        )}
                    />
                    {outlets.length > 1 && outlet && (
                        <select
                            aria-label={t('Outlet')}
                            className={selectClass}
                            value={outlet.id}
                            onChange={(e) =>
                                router.get(
                                    MenuController.index.url({
                                        query: { outlet: e.target.value },
                                    }),
                                )
                            }
                        >
                            {outlets.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                    )}
                </div>

                {!outlet && (
                    <p className="text-sm text-muted-foreground">
                        {t('Add an outlet to start taking table bookings.')}
                    </p>
                )}

                {outlet && (
                    <>
                        <div className="flex flex-wrap items-center gap-3 rounded-xl border p-3 text-sm">
                            <span>{t('Public menu page:')}</span>
                            <a
                                href={outlet.public_url}
                                target="_blank"
                                rel="noreferrer"
                                className="break-all underline"
                            >
                                {outlet.public_url}
                            </a>
                            <a
                                href={outlet.qr_url}
                                className="ml-auto underline"
                            >
                                {t('Download the QR code')}
                            </a>
                        </div>

                        {sections.map((section) => (
                            <section
                                key={section.id}
                                className="flex flex-col gap-2"
                            >
                                <div className="flex items-center gap-2">
                                    <h2 className="font-medium">
                                        {Object.values(section.name)[0]}
                                    </h2>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setAdding(section.id)}
                                    >
                                        {t('Add a dish')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-destructive"
                                        onClick={() =>
                                            window.confirm(
                                                t(
                                                    'Delete this section and its dishes?',
                                                ),
                                            ) &&
                                            router.delete(
                                                MenuController.destroySection.url(
                                                    section.id,
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('Delete section')}
                                    </Button>
                                </div>
                                {adding === section.id && (
                                    <ItemForm
                                        sectionId={section.id}
                                        locales={locales}
                                        labels={labels}
                                        allergens={allergens}
                                        currency={currency}
                                        onDone={() => setAdding(null)}
                                    />
                                )}
                                <ul className="divide-y rounded-xl border">
                                    {section.items.length === 0 && (
                                        <li className="px-3 py-2 text-sm text-muted-foreground">
                                            {t('No dishes yet.')}
                                        </li>
                                    )}
                                    {section.items.map((item) => (
                                        <ItemRow
                                            key={item.id}
                                            item={item}
                                            sectionId={section.id}
                                            locales={locales}
                                            labels={labels}
                                            allergens={allergens}
                                            currency={currency}
                                        />
                                    ))}
                                </ul>
                            </section>
                        ))}

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                sectionForm.post(
                                    MenuController.storeSection.url(),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            sectionForm.reset('name'),
                                    },
                                );
                            }}
                            className="flex flex-wrap items-end gap-2 rounded-xl border border-dashed p-3"
                        >
                            {locales.map((l) => (
                                <div key={l} className="grid gap-1">
                                    <Label htmlFor={`section-${l}`}>
                                        {t('New section (:language)', {
                                            language: l.toUpperCase(),
                                        })}
                                    </Label>
                                    <Input
                                        id={`section-${l}`}
                                        placeholder={t('e.g. Starters')}
                                        value={sectionForm.data.name[l] ?? ''}
                                        onChange={(e) =>
                                            sectionForm.setData('name', {
                                                ...sectionForm.data.name,
                                                [l]: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                            ))}
                            <Button
                                variant="outline"
                                disabled={sectionForm.processing}
                            >
                                {t('Add section')}
                            </Button>
                        </form>
                    </>
                )}
            </div>
        </>
    );
}

RestaurantMenu.layout = {
    breadcrumbs: [
        { title: 'Restaurant & Bar', href: RestaurantController() },
        { title: 'Menu', href: MenuController.index() },
    ],
};
