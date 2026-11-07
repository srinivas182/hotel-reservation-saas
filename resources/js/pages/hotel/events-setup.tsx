import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import EventController from '@/actions/App/Domains/Events/Http/Controllers/EventController';
import EventQuoteController from '@/actions/App/Domains/Events/Http/Controllers/EventQuoteController';
import EventSetupController from '@/actions/App/Domains/Events/Http/Controllers/EventSetupController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Option = { value: string; label: string };
type Space = {
    id: string;
    name: Record<string, string>;
    capacities: Record<string, number>;
    combines: string[];
    active: boolean;
};
type Block = {
    id: number;
    name: Record<string, string>;
    starts_at: string;
    ends_at: string;
    covers: number[];
};
type Pack = {
    id: number;
    kind: string;
    name: Record<string, string>;
    price: number;
    unit: string;
    active: boolean;
};
type Props = {
    spaces: Space[];
    blocks: Block[];
    prices: { space: string; block: number; price: number }[];
    packages: Pack[];
    layouts: Option[];
    locales: string[];
    currency: string;
    settings: {
        deposit_percent: number;
        option_days: number;
        quote_valid_days: number;
        terms: Record<string, string>;
    };
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const first = (name: Record<string, string>) => Object.values(name)[0] ?? '';

function SpaceForm({
    space,
    spaces,
    layouts,
    locales,
}: {
    space?: Space;
    spaces: Space[];
    layouts: Option[];
    locales: string[];
}) {
    const form = useForm({
        name: space?.name ?? Object.fromEntries(locales.map((l) => [l, ''])),
        capacities: space?.capacities ?? {},
        combines: space?.combines ?? [],
        active: space?.active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (space) {
                    form.put(EventSetupController.updateSpace.url(space.id), {
                        preserveScroll: true,
                    });
                } else {
                    form.post(EventSetupController.storeSpace.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }
            }}
            className="flex flex-wrap items-end gap-2 rounded-lg border p-3 text-sm"
        >
            {locales.map((l) => (
                <Input
                    key={l}
                    aria-label={t('Name (:language)', {
                        language: l.toUpperCase(),
                    })}
                    placeholder={t('Name (:language)', {
                        language: l.toUpperCase(),
                    })}
                    className="w-44"
                    value={form.data.name[l] ?? ''}
                    onChange={(e) =>
                        form.setData('name', {
                            ...form.data.name,
                            [l]: e.target.value,
                        })
                    }
                />
            ))}
            {layouts.map((l) => (
                <label key={l.value} className="grid gap-0.5 text-xs">
                    {l.label}
                    <Input
                        type="number"
                        min={0}
                        className="w-20"
                        value={form.data.capacities[l.value] ?? ''}
                        onChange={(e) =>
                            form.setData('capacities', {
                                ...form.data.capacities,
                                [l.value]: Number(e.target.value),
                            })
                        }
                    />
                </label>
            ))}
            {spaces.filter((s) => s.id !== space?.id).length > 0 && (
                <label className="grid gap-0.5 text-xs">
                    {t('Made of (combined space)')}
                    <select
                        multiple
                        className="h-16 rounded-md border border-input bg-transparent px-2 text-xs"
                        value={form.data.combines}
                        onChange={(e) =>
                            form.setData(
                                'combines',
                                Array.from(e.target.selectedOptions).map(
                                    (o) => o.value,
                                ),
                            )
                        }
                    >
                        {spaces
                            .filter((s) => s.id !== space?.id)
                            .map((s) => (
                                <option key={s.id} value={s.id}>
                                    {first(s.name)}
                                </option>
                            ))}
                    </select>
                </label>
            )}
            <label className="flex items-center gap-1 text-xs">
                <input
                    type="checkbox"
                    checked={form.data.active}
                    onChange={(e) => form.setData('active', e.target.checked)}
                />
                {t('Active')}
            </label>
            <Button size="sm" disabled={form.processing}>
                {space ? t('Save') : t('Add space')}
            </Button>
            <InputError message={errors.name} />
        </form>
    );
}

function BlockForm({
    block,
    blocks,
    locales,
}: {
    block?: Block;
    blocks: Block[];
    locales: string[];
}) {
    const form = useForm({
        name: block?.name ?? Object.fromEntries(locales.map((l) => [l, ''])),
        starts_at: block?.starts_at ?? '08:00',
        ends_at: block?.ends_at ?? '13:00',
        covers: block?.covers ?? [],
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (block) {
                    form.put(EventSetupController.updateBlock.url(block.id), {
                        preserveScroll: true,
                    });
                } else {
                    form.post(EventSetupController.storeBlock.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }
            }}
            className="flex flex-wrap items-end gap-2 text-sm"
        >
            {locales.map((l) => (
                <Input
                    key={l}
                    aria-label={t('Name (:language)', {
                        language: l.toUpperCase(),
                    })}
                    placeholder={t('e.g. Morning')}
                    className="w-36"
                    value={form.data.name[l] ?? ''}
                    onChange={(e) =>
                        form.setData('name', {
                            ...form.data.name,
                            [l]: e.target.value,
                        })
                    }
                />
            ))}
            <Input
                type="time"
                aria-label={t('From')}
                className="w-28"
                value={form.data.starts_at}
                onChange={(e) => form.setData('starts_at', e.target.value)}
            />
            <Input
                type="time"
                aria-label={t('To')}
                className="w-28"
                value={form.data.ends_at}
                onChange={(e) => form.setData('ends_at', e.target.value)}
            />
            <label className="flex items-center gap-1 text-xs">
                {t('Also takes')}
                <select
                    multiple
                    className="h-14 rounded-md border border-input bg-transparent px-2 text-xs"
                    value={form.data.covers.map(String)}
                    onChange={(e) =>
                        form.setData(
                            'covers',
                            Array.from(e.target.selectedOptions).map((o) =>
                                Number(o.value),
                            ),
                        )
                    }
                >
                    {blocks
                        .filter((b) => b.id !== block?.id)
                        .map((b) => (
                            <option key={b.id} value={b.id}>
                                {first(b.name)}
                            </option>
                        ))}
                </select>
            </label>
            <Button size="sm" variant="outline" disabled={form.processing}>
                {block ? t('Save') : t('Add block')}
            </Button>
        </form>
    );
}

function PackageForm({
    pack,
    locales,
    currency,
}: {
    pack?: Pack;
    locales: string[];
    currency: string;
}) {
    const form = useForm({
        kind: pack?.kind ?? 'package',
        name: pack?.name ?? Object.fromEntries(locales.map((l) => [l, ''])),
        price: pack?.price ?? 0,
        unit: pack?.unit ?? 'per_person',
        active: pack?.active ?? true,
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (pack) {
                    form.put(EventSetupController.updatePackage.url(pack.id), {
                        preserveScroll: true,
                    });
                } else {
                    form.post(EventSetupController.storePackage.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }
            }}
            className="flex flex-wrap items-end gap-2 text-sm"
        >
            <select
                aria-label={t('Kind')}
                className={selectClass}
                value={form.data.kind}
                onChange={(e) => form.setData('kind', e.target.value)}
            >
                <option value="package">{t('Package')}</option>
                <option value="extra">{t('Extra')}</option>
            </select>
            {locales.map((l) => (
                <Input
                    key={l}
                    aria-label={t('Name (:language)', {
                        language: l.toUpperCase(),
                    })}
                    placeholder={t('e.g. Day package')}
                    className="w-48"
                    value={form.data.name[l] ?? ''}
                    onChange={(e) =>
                        form.setData('name', {
                            ...form.data.name,
                            [l]: e.target.value,
                        })
                    }
                />
            ))}
            <Input
                type="number"
                min={0}
                step="any"
                aria-label={t('Price (:currency)', { currency })}
                className="w-28"
                value={form.data.price}
                onChange={(e) => form.setData('price', Number(e.target.value))}
            />
            <select
                aria-label={t('Unit')}
                className={selectClass}
                value={form.data.unit}
                onChange={(e) => form.setData('unit', e.target.value)}
            >
                <option value="per_person">{t('per person')}</option>
                <option value="per_unit">{t('per unit')}</option>
                <option value="per_event">{t('per event')}</option>
            </select>
            <label className="flex items-center gap-1 text-xs">
                <input
                    type="checkbox"
                    checked={form.data.active}
                    onChange={(e) => form.setData('active', e.target.checked)}
                />
                {t('Active')}
            </label>
            <Button size="sm" variant="outline" disabled={form.processing}>
                {pack ? t('Save') : t('Add')}
            </Button>
        </form>
    );
}

function SettingsForm({
    settings,
    locales,
}: {
    settings: Props['settings'];
    locales: string[];
}) {
    const form = useForm(settings);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(EventQuoteController.settings.url(), {
                    preserveScroll: true,
                });
            }}
            className="grid gap-3 rounded-xl border p-3 text-sm sm:grid-cols-3"
        >
            <label className="grid gap-1">
                {t('Deposit before confirming (%)')}
                <Input
                    type="number"
                    min={0}
                    max={100}
                    value={form.data.deposit_percent}
                    onChange={(e) =>
                        form.setData('deposit_percent', Number(e.target.value))
                    }
                />
            </label>
            <label className="grid gap-1">
                {t('Option lasts (days)')}
                <Input
                    type="number"
                    min={1}
                    value={form.data.option_days}
                    onChange={(e) =>
                        form.setData('option_days', Number(e.target.value))
                    }
                />
            </label>
            <label className="grid gap-1">
                {t('Quotes valid for (days)')}
                <Input
                    type="number"
                    min={1}
                    value={form.data.quote_valid_days}
                    onChange={(e) =>
                        form.setData('quote_valid_days', Number(e.target.value))
                    }
                />
            </label>
            {locales.map((l) => (
                <label key={l} className="grid gap-1 sm:col-span-3">
                    {t('Event terms (:language)', {
                        language: l.toUpperCase(),
                    })}
                    <textarea
                        className="min-h-20 rounded-md border border-input bg-transparent px-3 py-2"
                        value={form.data.terms[l] ?? ''}
                        onChange={(e) =>
                            form.setData('terms', {
                                ...form.data.terms,
                                [l]: e.target.value,
                            })
                        }
                    />
                </label>
            ))}
            <div>
                <Button size="sm" disabled={form.processing}>
                    {t('Save')}
                </Button>
            </div>
        </form>
    );
}

export default function EventsSetup({
    spaces,
    blocks,
    prices,
    packages,
    layouts,
    locales,
    currency,
    settings,
}: Props) {
    const [grid, setGrid] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            prices.map((p) => [`${p.space}|${p.block}`, String(p.price)]),
        ),
    );
    const priceForm = useForm({
        prices: [] as { space: string; block: number; price: string | null }[],
    });

    const savePrices = () => {
        priceForm.transform(() => ({
            prices: spaces.flatMap((s) =>
                blocks.map((b) => ({
                    space: s.id,
                    block: b.id,
                    price: grid[`${s.id}|${b.id}`] || null,
                })),
            ),
        }));
        priceForm.put(EventSetupController.savePrices.url(), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={t('Spaces, prices and packages')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <Heading
                    title={t('Spaces, prices and packages')}
                    description={t('What event quotes are built from.')}
                />

                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('Deposits, options and terms')}
                    />
                    <SettingsForm settings={settings} locales={locales} />
                </section>

                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('Spaces and capacity by layout')}
                    />
                    {spaces.map((s) => (
                        <SpaceForm
                            key={s.id}
                            space={s}
                            spaces={spaces}
                            layouts={layouts}
                            locales={locales}
                        />
                    ))}
                    <SpaceForm
                        spaces={spaces}
                        layouts={layouts}
                        locales={locales}
                    />
                </section>

                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('Time blocks')}
                        description={t(
                            'A full day "also takes" the morning and the afternoon.',
                        )}
                    />
                    {blocks.map((b) => (
                        <BlockForm
                            key={b.id}
                            block={b}
                            blocks={blocks}
                            locales={locales}
                        />
                    ))}
                    <BlockForm blocks={blocks} locales={locales} />
                </section>

                {spaces.length > 0 && blocks.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <Heading
                            variant="small"
                            title={t('Space prices (:currency)', { currency })}
                        />
                        <div className="overflow-x-auto">
                            <table className="text-sm">
                                <thead>
                                    <tr>
                                        <th />
                                        {blocks.map((b) => (
                                            <th
                                                key={b.id}
                                                className="px-2 text-left font-medium"
                                            >
                                                {first(b.name)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {spaces.map((s) => (
                                        <tr key={s.id}>
                                            <td className="pr-2">
                                                {first(s.name)}
                                            </td>
                                            {blocks.map((b) => (
                                                <td key={b.id} className="p-1">
                                                    <Input
                                                        type="number"
                                                        min={0}
                                                        className="w-28"
                                                        aria-label={`${first(s.name)} ${first(b.name)}`}
                                                        value={
                                                            grid[
                                                                `${s.id}|${b.id}`
                                                            ] ?? ''
                                                        }
                                                        onChange={(e) =>
                                                            setGrid({
                                                                ...grid,
                                                                [`${s.id}|${b.id}`]:
                                                                    e.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div>
                            <Button
                                size="sm"
                                onClick={savePrices}
                                disabled={priceForm.processing}
                            >
                                {t('Save prices')}
                            </Button>
                        </div>
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Packages and extras')} />
                    {packages.map((p) => (
                        <PackageForm
                            key={p.id}
                            pack={p}
                            locales={locales}
                            currency={currency}
                        />
                    ))}
                    <PackageForm locales={locales} currency={currency} />
                </section>
            </div>
        </>
    );
}

EventsSetup.layout = {
    breadcrumbs: [
        { title: 'Events', href: EventController.index() },
        {
            title: 'Spaces, prices and packages',
            href: EventSetupController.index(),
        },
    ],
};
