import { Head, Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import PlatformBillingController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformBillingController';
import { EntitiesTab } from '@/components/billing/entities';
import type { Entity, Report } from '@/components/billing/entities';
import { GatewaysTab } from '@/components/billing/gateways';
import type { Driver, Gateway } from '@/components/billing/gateways';
import InputError from '@/components/input-error';
import {
    DataTable,
    Field,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    StatTile,
    Switch,
    Tabs,
    formatMoney,
    minorFactor,
    useTabs,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type PlanRow = {
    id: string;
    code: string;
    name: Record<string, string>;
    modules: string[];
    staff_limit: number | null;
    ai_credits: number;
    email_credits: number;
    prices: Record<string, { monthly?: number; yearly?: number }>;
    trial_days: number;
    is_default: boolean;
    active: boolean;
    hotels: number;
};
type PackRow = {
    id: number;
    code: string;
    name: Record<string, string>;
    kind: string;
    credits: number;
    prices: Record<string, number>;
    valid_months: number;
    active: boolean;
};
type Props = {
    entities: Entity[];
    report: Report;
    gateways: Gateway[];
    drivers: Driver[];
    plans: PlanRow[];
    packs: PackRow[];
    settings: { ai_weights: Record<string, number>; buffer_percent: number };
    modules: { value: string; label: string }[];
    subscriptions: {
        hotel_id: string;
        hotel: string | null;
        plan: string | null;
        status: string;
        period_end: string;
        price_minor: number;
        currency: string;
    }[];
};

const label = (name: Record<string, string>, code: string) =>
    name.en || name.fr || code;

/** An amount typed in normal units (25000, 39.00), stored in minor units. */
function MoneyInput({
    currency,
    minor,
    onChange,
    ariaLabel,
}: {
    currency: string;
    minor: number | undefined;
    onChange: (minor: number | undefined) => void;
    ariaLabel: string;
}) {
    const factor = minorFactor(currency);

    return (
        <Input
            inputMode="decimal"
            aria-label={ariaLabel}
            className="text-right tabular-nums"
            value={minor === undefined ? '' : String(minor / factor)}
            onChange={(e) => {
                const raw = e.target.value
                    .replace(',', '.')
                    .replace(/[^\d.]/g, '');
                onChange(
                    raw === '' ? undefined : Math.round(Number(raw) * factor),
                );
            }}
        />
    );
}

function NameFields({
    value,
    onChange,
    code,
    onCode,
    errors,
}: {
    value: Record<string, string>;
    onChange: (v: Record<string, string>) => void;
    code: string;
    onCode: (v: string) => void;
    errors: Record<string, string | undefined>;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-3">
            <Field
                label={t('Code')}
                hint={t('Short and unique, e.g. pro')}
                error={errors.code}
            >
                <Input
                    value={code}
                    className="font-mono"
                    onChange={(e) => onCode(e.target.value)}
                />
            </Field>
            <Field label={t('Name (French)')}>
                <Input
                    value={value.fr ?? ''}
                    onChange={(e) => onChange({ ...value, fr: e.target.value })}
                />
            </Field>
            <Field label={t('Name (English)')}>
                <Input
                    value={value.en ?? ''}
                    onChange={(e) => onChange({ ...value, en: e.target.value })}
                />
            </Field>
        </div>
    );
}

function PlanEditor({
    plan,
    modules,
    open,
    onClose,
}: {
    plan: PlanRow | null;
    modules: Props['modules'];
    open: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        code: plan?.code ?? '',
        name: plan?.name ?? { fr: '', en: '' },
        modules: plan?.modules ?? [],
        staff_limit: plan?.staff_limit ?? null,
        ai_credits: plan?.ai_credits ?? 1000,
        email_credits: plan?.email_credits ?? 500,
        prices: plan?.prices ?? { XOF: {} },
        trial_days: plan?.trial_days ?? 14,
        is_default: plan?.is_default ?? false,
        active: plan?.active ?? true,
    });
    const [currency, setCurrency] = useState('');
    const errors = form.errors as Record<string, string | undefined>;
    const setPrice = (
        c: string,
        period: 'monthly' | 'yearly',
        minor?: number,
    ) =>
        form.setData('prices', {
            ...form.data.prices,
            [c]: { ...form.data.prices[c], [period]: minor },
        });

    const save = () => {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (plan) {
            form.put(
                PlatformBillingController.updatePlan.url(plan.id),
                options,
            );
        } else {
            form.post(PlatformBillingController.storePlan.url(), options);
        }
    };

    return (
        <SidePanel
            open={open}
            onClose={onClose}
            title={
                plan
                    ? t('Edit plan :name', {
                          name: label(plan.name, plan.code),
                      })
                    : t('New plan')
            }
            description={
                plan
                    ? t(
                          ':count hotel(s) on this plan. Price changes apply at their next renewal.',
                          { count: plan.hotels },
                      )
                    : t('What hotels get and what they pay, per currency.')
            }
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button disabled={form.processing} onClick={save}>
                        {plan ? t('Save plan') : t('Create plan')}
                    </Button>
                </>
            }
        >
            <div className="grid gap-6">
                <FieldGroup title={t('Name')}>
                    <NameFields
                        value={form.data.name}
                        onChange={(v) => form.setData('name', v)}
                        code={form.data.code}
                        onCode={(v) => form.setData('code', v)}
                        errors={errors}
                    />
                </FieldGroup>

                <FieldGroup
                    title={t('What is included')}
                    description={t('Credits renew each month.')}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('AI credits / month')}>
                            <Input
                                type="number"
                                min={0}
                                value={form.data.ai_credits}
                                onChange={(e) =>
                                    form.setData(
                                        'ai_credits',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </Field>
                        <Field label={t('Email credits / month')}>
                            <Input
                                type="number"
                                min={0}
                                value={form.data.email_credits}
                                onChange={(e) =>
                                    form.setData(
                                        'email_credits',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label={t('Staff limit')}
                            hint={t('Empty: no limit')}
                        >
                            <Input
                                type="number"
                                min={1}
                                value={form.data.staff_limit ?? ''}
                                onChange={(e) =>
                                    form.setData(
                                        'staff_limit',
                                        e.target.value === ''
                                            ? null
                                            : Number(e.target.value),
                                    )
                                }
                            />
                        </Field>
                        <Field label={t('Trial days')}>
                            <Input
                                type="number"
                                min={0}
                                value={form.data.trial_days}
                                onChange={(e) =>
                                    form.setData(
                                        'trial_days',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </Field>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {modules.map((m) => (
                            <Switch
                                key={m.value}
                                label={m.label}
                                checked={form.data.modules.includes(m.value)}
                                onChange={(on) =>
                                    form.setData(
                                        'modules',
                                        on
                                            ? [...form.data.modules, m.value]
                                            : form.data.modules.filter(
                                                  (x) => x !== m.value,
                                              ),
                                    )
                                }
                            />
                        ))}
                    </div>
                </FieldGroup>

                <FieldGroup
                    title={t('Prices')}
                    description={t(
                        'In normal amounts: 25000 for 25 000 F CFA, 39 for €39.00. Leave yearly empty to offer monthly only.',
                    )}
                >
                    <div className="overflow-hidden rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/40 text-xs text-muted-foreground">
                                    <th className="px-3 py-2 text-left font-medium">
                                        {t('Currency')}
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        {t('Monthly')}
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        {t('Yearly')}
                                    </th>
                                    <th className="w-10" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {Object.keys(form.data.prices).map((c) => (
                                    <tr key={c}>
                                        <td className="px-3 py-2 font-mono text-xs font-semibold">
                                            {c}
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <MoneyInput
                                                currency={c}
                                                ariaLabel={`${c} ${t('Monthly')}`}
                                                minor={
                                                    form.data.prices[c]?.monthly
                                                }
                                                onChange={(v) =>
                                                    setPrice(c, 'monthly', v)
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <MoneyInput
                                                currency={c}
                                                ariaLabel={`${c} ${t('Yearly')}`}
                                                minor={
                                                    form.data.prices[c]?.yearly
                                                }
                                                onChange={(v) =>
                                                    setPrice(c, 'yearly', v)
                                                }
                                            />
                                        </td>
                                        <td className="pr-2 text-right">
                                            <button
                                                type="button"
                                                className="text-xs text-muted-foreground hover:text-destructive"
                                                aria-label={t(
                                                    'Remove :currency',
                                                    { currency: c },
                                                )}
                                                onClick={() => {
                                                    const next = {
                                                        ...form.data.prices,
                                                    };
                                                    delete next[c];
                                                    form.setData(
                                                        'prices',
                                                        next,
                                                    );
                                                }}
                                            >
                                                ✕
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="flex items-center gap-2 border-t bg-muted/20 px-3 py-2">
                            <Input
                                aria-label={t('Add a currency')}
                                placeholder="EUR"
                                className="h-8 w-20 font-mono uppercase"
                                value={currency}
                                onChange={(e) =>
                                    setCurrency(
                                        e.target.value
                                            .toUpperCase()
                                            .replace(/[^A-Z]/g, '')
                                            .slice(0, 3),
                                    )
                                }
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={
                                    currency.length !== 3 ||
                                    currency in form.data.prices
                                }
                                onClick={() => {
                                    form.setData('prices', {
                                        ...form.data.prices,
                                        [currency]: {},
                                    });
                                    setCurrency('');
                                }}
                            >
                                <Plus /> {t('Add a currency')}
                            </Button>
                        </div>
                    </div>
                    <InputError message={errors.prices} />
                </FieldGroup>

                <FieldGroup title={t('Availability')}>
                    <Switch
                        label={t('Offered to hotels')}
                        hint={t(
                            'Switched off: existing hotels keep it, new ones cannot choose it.',
                        )}
                        checked={form.data.active}
                        onChange={(v) => form.setData('active', v)}
                    />
                    <Switch
                        label={t('Default plan for new hotels (trial)')}
                        checked={form.data.is_default}
                        onChange={(v) => form.setData('is_default', v)}
                    />
                </FieldGroup>
            </div>
        </SidePanel>
    );
}

function PackEditor({
    pack,
    open,
    onClose,
}: {
    pack: PackRow | null;
    open: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        code: pack?.code ?? '',
        name: pack?.name ?? { fr: '', en: '' },
        kind: pack?.kind ?? 'ai',
        credits: pack?.credits ?? 1000,
        prices: pack?.prices ?? { XOF: 0 },
        valid_months: pack?.valid_months ?? 12,
        active: pack?.active ?? true,
    });
    const [currency, setCurrency] = useState('');
    const errors = form.errors as Record<string, string | undefined>;

    const save = () => {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (pack) {
            form.put(
                PlatformBillingController.updatePack.url(pack.id),
                options,
            );
        } else {
            form.post(PlatformBillingController.storePack.url(), options);
        }
    };

    return (
        <SidePanel
            open={open}
            onClose={onClose}
            title={
                pack
                    ? t('Edit pack :name', {
                          name: label(pack.name, pack.code),
                      })
                    : t('New credit pack')
            }
            description={t(
                'Extra credits hotels can buy when the monthly ones run out.',
            )}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button disabled={form.processing} onClick={save}>
                        {pack ? t('Save pack') : t('Create pack')}
                    </Button>
                </>
            }
        >
            <div className="grid gap-6">
                <FieldGroup title={t('Name')}>
                    <NameFields
                        value={form.data.name}
                        onChange={(v) => form.setData('name', v)}
                        code={form.data.code}
                        onCode={(v) => form.setData('code', v)}
                        errors={errors}
                    />
                </FieldGroup>
                <FieldGroup title={t('Credits')}>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('Kind')}>
                            <select
                                className="h-9 w-full"
                                value={form.data.kind}
                                onChange={(e) =>
                                    form.setData('kind', e.target.value)
                                }
                            >
                                <option value="ai">{t('AI credits')}</option>
                                <option value="email">
                                    {t('Email credits')}
                                </option>
                            </select>
                        </Field>
                        <Field label={t('Credits')} error={errors.credits}>
                            <Input
                                type="number"
                                min={1}
                                value={form.data.credits}
                                onChange={(e) =>
                                    form.setData(
                                        'credits',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </Field>
                        <Field label={t('Valid for (months)')}>
                            <Input
                                type="number"
                                min={1}
                                max={36}
                                value={form.data.valid_months}
                                onChange={(e) =>
                                    form.setData(
                                        'valid_months',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </Field>
                    </div>
                </FieldGroup>
                <FieldGroup
                    title={t('Prices')}
                    description={t('In normal amounts, per currency.')}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        {Object.keys(form.data.prices).map((c) => (
                            <Field key={c} label={c}>
                                <MoneyInput
                                    currency={c}
                                    ariaLabel={c}
                                    minor={form.data.prices[c]}
                                    onChange={(v) =>
                                        form.setData('prices', {
                                            ...form.data.prices,
                                            [c]: v ?? 0,
                                        })
                                    }
                                />
                            </Field>
                        ))}
                    </div>
                    <div className="flex items-center gap-2">
                        <Input
                            aria-label={t('Add a currency')}
                            placeholder="EUR"
                            className="h-8 w-20 font-mono uppercase"
                            value={currency}
                            onChange={(e) =>
                                setCurrency(
                                    e.target.value
                                        .toUpperCase()
                                        .replace(/[^A-Z]/g, '')
                                        .slice(0, 3),
                                )
                            }
                        />
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={
                                currency.length !== 3 ||
                                currency in form.data.prices
                            }
                            onClick={() => {
                                form.setData('prices', {
                                    ...form.data.prices,
                                    [currency]: 0,
                                });
                                setCurrency('');
                            }}
                        >
                            <Plus /> {t('Add a currency')}
                        </Button>
                    </div>
                    <InputError message={errors.prices} />
                </FieldGroup>
                <FieldGroup title={t('Availability')}>
                    <Switch
                        label={t('On sale')}
                        checked={form.data.active}
                        onChange={(v) => form.setData('active', v)}
                    />
                </FieldGroup>
            </div>
        </SidePanel>
    );
}

function CreditRules({ settings }: { settings: Props['settings'] }) {
    const form = useForm(settings);

    return (
        <Section
            aside
            title={t('How credits are counted')}
            description={t(
                '100 = one credit. An assistant reply or a voice note costs 1 credit by default, a translation 0.5.',
            )}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(PlatformBillingController.settings.url(), {
                        preserveScroll: true,
                    });
                }}
                className="grid gap-4"
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {Object.keys(form.data.ai_weights).map((task) => (
                        <Field
                            key={task}
                            label={t('Credits per :task (× 100)', { task })}
                        >
                            <Input
                                type="number"
                                min={0}
                                value={form.data.ai_weights[task]}
                                onChange={(e) =>
                                    form.setData('ai_weights', {
                                        ...form.data.ai_weights,
                                        [task]: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                    ))}
                    <Field
                        label={t('Buffer after credits run out (%)')}
                        hint={t(
                            'Replies keep going this much longer, so guests are never cut off mid-conversation.',
                        )}
                    >
                        <Input
                            type="number"
                            min={0}
                            max={50}
                            value={form.data.buffer_percent}
                            onChange={(e) =>
                                form.setData(
                                    'buffer_percent',
                                    Number(e.target.value),
                                )
                            }
                        />
                    </Field>
                </div>
                <div>
                    <Button size="sm" disabled={form.processing}>
                        {t('Save')}
                    </Button>
                </div>
            </form>
        </Section>
    );
}

const statusVariant = (status: string) =>
    status === 'active'
        ? 'default'
        : status === 'past_due'
          ? 'destructive'
          : 'outline';

export default function PlatformBilling({
    plans,
    packs,
    settings,
    modules,
    subscriptions,
    entities,
    report,
    gateways,
    drivers,
}: Props) {
    const tabs = [
        { key: 'overview', label: t('Overview') },
        { key: 'plans', label: t('Plans'), count: plans.length },
        { key: 'packs', label: t('Credit packs'), count: packs.length },
        {
            key: 'companies',
            label: t('Issuing companies'),
            count: entities.length,
        },
        {
            key: 'payments',
            label: t('Payment providers'),
            count: gateways.length,
        },
    ];
    const [tab, setTab] = useTabs(tabs);
    const [plan, setPlan] = useState<PlanRow | 'new' | null>(null);
    const [pack, setPack] = useState<PackRow | 'new' | null>(null);
    const moduleLabel = (v: string) =>
        modules.find((m) => m.value === v)?.label ?? v;
    const firstPrice = (p: PlanRow) => {
        const c = Object.keys(p.prices)[0];

        return c && p.prices[c]?.monthly !== undefined
            ? formatMoney(p.prices[c].monthly as number, c)
            : '—';
    };
    const trialing = subscriptions.filter(
        (s) => s.status === 'trialing',
    ).length;
    const active = subscriptions.filter((s) => s.status === 'active').length;

    return (
        <>
            <Head title={t('Billing')} />
            <PageBody>
                <PageHeader
                    title={t('Billing')}
                    description={t(
                        'Plans and prices, credit packs, invoices and how hotels pay.',
                    )}
                    actions={
                        tab === 'plans' ? (
                            <Button onClick={() => setPlan('new')}>
                                <Plus /> {t('New plan')}
                            </Button>
                        ) : tab === 'packs' ? (
                            <Button onClick={() => setPack('new')}>
                                <Plus /> {t('New credit pack')}
                            </Button>
                        ) : null
                    }
                />
                <Tabs tabs={tabs} active={tab} onSelect={setTab} />

                {tab === 'overview' && (
                    <>
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            {Object.entries(report.mrr).length === 0 && (
                                <StatTile
                                    label={t('Monthly revenue')}
                                    value="—"
                                />
                            )}
                            {Object.entries(report.mrr).map(([c, v]) => (
                                <StatTile
                                    key={c}
                                    label={t('Monthly revenue (:currency)', {
                                        currency: c,
                                    })}
                                    value={v}
                                />
                            ))}
                            <StatTile
                                label={t('Paying hotels')}
                                value={active}
                            />
                            <StatTile label={t('On trial')} value={trialing} />
                            {Object.entries(report.overdue).map(([c, v]) => (
                                <StatTile
                                    key={`o-${c}`}
                                    label={t('Overdue (:currency)', {
                                        currency: c,
                                    })}
                                    value={
                                        <span className="text-destructive">
                                            {v}
                                        </span>
                                    }
                                />
                            ))}
                        </div>
                        <Section
                            flush
                            title={t('Hotels')}
                            description={t(
                                'Plan, monthly revenue and AI cost per hotel this month.',
                            )}
                        >
                            <DataTable
                                rows={report.hotels}
                                rowKey={(h) => h.hotel_id}
                                empty={t('No subscriptions yet.')}
                                columns={[
                                    {
                                        key: 'hotel',
                                        header: t('Hotel'),
                                        cell: (h) => (
                                            <Link
                                                href={PlatformBillingController.hotel(
                                                    h.hotel_id,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {h.hotel}
                                            </Link>
                                        ),
                                    },
                                    {
                                        key: 'plan',
                                        header: t('Plan'),
                                        cell: (h) => h.plan ?? '—',
                                    },
                                    {
                                        key: 'status',
                                        header: t('Status'),
                                        cell: (h) => (
                                            <Badge
                                                variant={statusVariant(
                                                    h.status,
                                                )}
                                            >
                                                {h.status}
                                            </Badge>
                                        ),
                                    },
                                    {
                                        key: 'mrr',
                                        header: t('Monthly'),
                                        align: 'right',
                                        cell: (h) => h.mrr,
                                    },
                                    {
                                        key: 'ai',
                                        header: t('AI cost (US$)'),
                                        align: 'right',
                                        cell: (h) => h.ai_cost_usd,
                                    },
                                    {
                                        key: 'overdue',
                                        header: t('Overdue'),
                                        align: 'right',
                                        cell: (h) =>
                                            h.overdue ? (
                                                <span className="text-destructive">
                                                    {h.overdue}
                                                </span>
                                            ) : (
                                                '—'
                                            ),
                                    },
                                ]}
                            />
                        </Section>
                    </>
                )}

                {tab === 'plans' && (
                    <Section
                        flush
                        title={t('Plans')}
                        description={t(
                            'What hotels get and what they pay. Click a plan to edit it.',
                        )}
                    >
                        <DataTable
                            rows={plans}
                            rowKey={(p) => p.id}
                            empty={t('No plans yet.')}
                            onRowClick={(p) => setPlan(p)}
                            columns={[
                                {
                                    key: 'name',
                                    header: t('Plan'),
                                    cell: (p) => (
                                        <div>
                                            <div className="flex items-center gap-2 font-medium">
                                                {label(p.name, p.code)}
                                                {p.is_default && (
                                                    <Badge variant="secondary">
                                                        {t('Default')}
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="font-mono text-xs text-muted-foreground">
                                                {p.code}
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'price',
                                    header: t('From / month'),
                                    align: 'right',
                                    cell: firstPrice,
                                },
                                {
                                    key: 'credits',
                                    header: t('AI · e-mail credits'),
                                    align: 'right',
                                    cell: (p) =>
                                        `${p.ai_credits.toLocaleString()} · ${p.email_credits.toLocaleString()}`,
                                },
                                {
                                    key: 'modules',
                                    header: t('Modules'),
                                    cell: (p) => (
                                        <div className="flex flex-wrap gap-1">
                                            {p.modules.length === 0 ? (
                                                <span className="text-muted-foreground">
                                                    {t('Rooms only')}
                                                </span>
                                            ) : (
                                                p.modules.map((m) => (
                                                    <Badge
                                                        key={m}
                                                        variant="outline"
                                                    >
                                                        {moduleLabel(m)}
                                                    </Badge>
                                                ))
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'hotels',
                                    header: t('Hotels'),
                                    align: 'right',
                                    cell: (p) => p.hotels,
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (p) =>
                                        p.active ? (
                                            <Badge>{t('Active')}</Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                {t('Off')}
                                            </Badge>
                                        ),
                                },
                                {
                                    key: 'edit',
                                    header: '',
                                    align: 'right',
                                    cell: () => (
                                        <span className="text-xs text-brand-ink">
                                            {t('Edit')}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'packs' && (
                    <>
                        <Section
                            flush
                            title={t('Credit packs')}
                            description={t(
                                'Extra credits hotels can buy. Click a pack to edit it.',
                            )}
                        >
                            <DataTable
                                rows={packs}
                                rowKey={(p) => p.id}
                                empty={t('No credit packs yet.')}
                                onRowClick={(p) => setPack(p)}
                                columns={[
                                    {
                                        key: 'name',
                                        header: t('Pack'),
                                        cell: (p) => (
                                            <div>
                                                <div className="font-medium">
                                                    {label(p.name, p.code)}
                                                </div>
                                                <div className="font-mono text-xs text-muted-foreground">
                                                    {p.code}
                                                </div>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'kind',
                                        header: t('Kind'),
                                        cell: (p) => (
                                            <Badge variant="outline">
                                                {p.kind === 'ai'
                                                    ? t('AI credits')
                                                    : t('Email credits')}
                                            </Badge>
                                        ),
                                    },
                                    {
                                        key: 'credits',
                                        header: t('Credits'),
                                        align: 'right',
                                        cell: (p) => p.credits.toLocaleString(),
                                    },
                                    {
                                        key: 'prices',
                                        header: t('Prices'),
                                        align: 'right',
                                        cell: (p) =>
                                            Object.entries(p.prices)
                                                .map(([c, v]) =>
                                                    formatMoney(v, c),
                                                )
                                                .join(' · '),
                                    },
                                    {
                                        key: 'valid',
                                        header: t('Valid'),
                                        align: 'right',
                                        cell: (p) =>
                                            t(':count months', {
                                                count: p.valid_months,
                                            }),
                                    },
                                    {
                                        key: 'status',
                                        header: t('Status'),
                                        cell: (p) =>
                                            p.active ? (
                                                <Badge>{t('On sale')}</Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    {t('Off')}
                                                </Badge>
                                            ),
                                    },
                                ]}
                            />
                        </Section>
                        <CreditRules settings={settings} />
                    </>
                )}

                {tab === 'companies' && <EntitiesTab entities={entities} />}
                {tab === 'payments' && (
                    <GatewaysTab gateways={gateways} drivers={drivers} />
                )}
            </PageBody>

            {plan !== null && (
                <PlanEditor
                    key={plan === 'new' ? 'new' : plan.id}
                    plan={plan === 'new' ? null : plan}
                    modules={modules}
                    open
                    onClose={() => setPlan(null)}
                />
            )}
            {pack !== null && (
                <PackEditor
                    key={pack === 'new' ? 'new' : pack.id}
                    pack={pack === 'new' ? null : pack}
                    open
                    onClose={() => setPack(null)}
                />
            )}
        </>
    );
}

PlatformBilling.layout = {
    breadcrumbs: [
        { title: 'Billing', href: PlatformBillingController.index() },
    ],
};
