import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformBillingController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformBillingController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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

function PlanForm({
    plan,
    modules,
    onDone,
}: {
    plan?: PlanRow;
    modules: Props['modules'];
    onDone?: () => void;
}) {
    const currencies = Object.keys(plan?.prices ?? { XOF: {} });
    const form = useForm({
        code: plan?.code ?? '',
        name: plan?.name ?? { fr: '', en: '' },
        modules: plan?.modules ?? [],
        staff_limit: plan?.staff_limit ?? null,
        ai_credits: plan?.ai_credits ?? 1000,
        email_credits: plan?.email_credits ?? 500,
        prices: plan?.prices ?? { XOF: { monthly: 0, yearly: 0 } },
        trial_days: plan?.trial_days ?? 14,
        is_default: plan?.is_default ?? false,
        active: plan?.active ?? true,
    });
    const [currency, setCurrency] = useState('');
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (plan) {
                    form.put(
                        PlatformBillingController.updatePlan.url(plan.id),
                        { preserveScroll: true },
                    );
                } else {
                    form.post(PlatformBillingController.storePlan.url(), {
                        preserveScroll: true,
                        onSuccess: () => onDone?.(),
                    });
                }
            }}
            className="flex flex-col gap-2 rounded-xl border p-3 text-sm"
        >
            <div className="flex flex-wrap items-end gap-2">
                <Input
                    aria-label={t('Code')}
                    placeholder={t('Code')}
                    className="w-28"
                    value={form.data.code}
                    onChange={(e) => form.setData('code', e.target.value)}
                />
                <Input
                    aria-label="FR"
                    placeholder="Nom (FR)"
                    className="w-36"
                    value={form.data.name.fr ?? ''}
                    onChange={(e) =>
                        form.setData('name', {
                            ...form.data.name,
                            fr: e.target.value,
                        })
                    }
                />
                <Input
                    aria-label="EN"
                    placeholder="Name (EN)"
                    className="w-36"
                    value={form.data.name.en ?? ''}
                    onChange={(e) =>
                        form.setData('name', {
                            ...form.data.name,
                            en: e.target.value,
                        })
                    }
                />
                <label className="grid gap-0.5 text-xs">
                    {t('AI credits / month')}
                    <Input
                        type="number"
                        min={0}
                        className="w-24"
                        value={form.data.ai_credits}
                        onChange={(e) =>
                            form.setData('ai_credits', Number(e.target.value))
                        }
                    />
                </label>
                <label className="grid gap-0.5 text-xs">
                    {t('Email credits / month')}
                    <Input
                        type="number"
                        min={0}
                        className="w-24"
                        value={form.data.email_credits}
                        onChange={(e) =>
                            form.setData(
                                'email_credits',
                                Number(e.target.value),
                            )
                        }
                    />
                </label>
                <label className="grid gap-0.5 text-xs">
                    {t('Staff limit')}
                    <Input
                        type="number"
                        min={1}
                        className="w-20"
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
                </label>
                <label className="grid gap-0.5 text-xs">
                    {t('Trial days')}
                    <Input
                        type="number"
                        min={0}
                        className="w-20"
                        value={form.data.trial_days}
                        onChange={(e) =>
                            form.setData('trial_days', Number(e.target.value))
                        }
                    />
                </label>
            </div>
            <div className="flex flex-wrap gap-3">
                {modules.map((m) => (
                    <label
                        key={m.value}
                        className="flex items-center gap-1 text-xs"
                    >
                        <input
                            type="checkbox"
                            checked={form.data.modules.includes(m.value)}
                            onChange={() =>
                                form.setData(
                                    'modules',
                                    form.data.modules.includes(m.value)
                                        ? form.data.modules.filter(
                                              (x) => x !== m.value,
                                          )
                                        : [...form.data.modules, m.value],
                                )
                            }
                        />
                        {m.label}
                    </label>
                ))}
                <label className="flex items-center gap-1 text-xs">
                    <input
                        type="checkbox"
                        checked={form.data.is_default}
                        onChange={(e) =>
                            form.setData('is_default', e.target.checked)
                        }
                    />
                    {t('Default plan for new hotels (trial)')}
                </label>
                <label className="flex items-center gap-1 text-xs">
                    <input
                        type="checkbox"
                        checked={form.data.active}
                        onChange={(e) =>
                            form.setData('active', e.target.checked)
                        }
                    />
                    {t('Active')}
                </label>
            </div>
            <div className="flex flex-wrap items-end gap-2">
                <span className="text-xs text-muted-foreground">
                    {t(
                        'Prices in minor units (FCFA has none; EUR/USD in cents)',
                    )}
                </span>
                {Object.keys(form.data.prices)
                    .concat(currencies.filter((c) => !(c in form.data.prices)))
                    .map((c) => (
                        <span
                            key={c}
                            className="flex items-center gap-1 text-xs"
                        >
                            <b>{c}</b>
                            <Input
                                type="number"
                                min={0}
                                aria-label={`${c} ${t('Monthly')}`}
                                className="w-24"
                                value={form.data.prices[c]?.monthly ?? ''}
                                onChange={(e) =>
                                    form.setData('prices', {
                                        ...form.data.prices,
                                        [c]: {
                                            ...form.data.prices[c],
                                            monthly: Number(e.target.value),
                                        },
                                    })
                                }
                            />
                            <Input
                                type="number"
                                min={0}
                                aria-label={`${c} ${t('Yearly')}`}
                                className="w-28"
                                value={form.data.prices[c]?.yearly ?? ''}
                                onChange={(e) =>
                                    form.setData('prices', {
                                        ...form.data.prices,
                                        [c]: {
                                            ...form.data.prices[c],
                                            yearly: Number(e.target.value),
                                        },
                                    })
                                }
                            />
                        </span>
                    ))}
                <Input
                    aria-label={t('Add a currency')}
                    placeholder="EUR"
                    className="w-16"
                    value={currency}
                    onChange={(e) =>
                        setCurrency(e.target.value.toUpperCase().slice(0, 3))
                    }
                />
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        if (currency.length === 3) {
                            form.setData('prices', {
                                ...form.data.prices,
                                [currency]: { monthly: 0, yearly: 0 },
                            });
                            setCurrency('');
                        }
                    }}
                >
                    {t('Add a currency')}
                </Button>
            </div>
            <InputError message={errors.code ?? errors.prices} />
            <div className="flex items-center gap-2">
                <Button size="sm" disabled={form.processing}>
                    {plan ? t('Save') : t('Add plan')}
                </Button>
                {plan && (
                    <span className="text-xs text-muted-foreground">
                        {t(':count hotel(s)', { count: plan.hotels })}
                    </span>
                )}
            </div>
        </form>
    );
}

function PackForm({ pack }: { pack?: PackRow }) {
    const form = useForm({
        code: pack?.code ?? '',
        name: pack?.name ?? { fr: '', en: '' },
        kind: pack?.kind ?? 'ai',
        credits: pack?.credits ?? 1000,
        prices: pack?.prices ?? { XOF: 0 },
        valid_months: pack?.valid_months ?? 12,
        active: pack?.active ?? true,
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (pack) {
                    form.put(
                        PlatformBillingController.updatePack.url(pack.id),
                        { preserveScroll: true },
                    );
                } else {
                    form.post(PlatformBillingController.storePack.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }
            }}
            className="flex flex-wrap items-end gap-2 text-sm"
        >
            <Input
                aria-label={t('Code')}
                placeholder={t('Code')}
                className="w-28"
                value={form.data.code}
                onChange={(e) => form.setData('code', e.target.value)}
            />
            <Input
                aria-label="FR"
                placeholder="Nom (FR)"
                className="w-40"
                value={form.data.name.fr ?? ''}
                onChange={(e) =>
                    form.setData('name', {
                        ...form.data.name,
                        fr: e.target.value,
                    })
                }
            />
            <select
                aria-label={t('Kind')}
                className="flex h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                value={form.data.kind}
                onChange={(e) => form.setData('kind', e.target.value)}
            >
                <option value="ai">{t('AI credits')}</option>
                <option value="email">{t('Email credits')}</option>
            </select>
            <Input
                type="number"
                min={1}
                aria-label={t('Credits')}
                className="w-24"
                value={form.data.credits}
                onChange={(e) =>
                    form.setData('credits', Number(e.target.value))
                }
            />
            {Object.keys(form.data.prices).map((c) => (
                <span key={c} className="flex items-center gap-1 text-xs">
                    <b>{c}</b>
                    <Input
                        type="number"
                        min={0}
                        aria-label={`${c}`}
                        className="w-24"
                        value={form.data.prices[c]}
                        onChange={(e) =>
                            form.setData('prices', {
                                ...form.data.prices,
                                [c]: Number(e.target.value),
                            })
                        }
                    />
                </span>
            ))}
            <Button size="sm" variant="outline" disabled={form.processing}>
                {pack ? t('Save') : t('Add pack')}
            </Button>
        </form>
    );
}

function Settings({ settings }: { settings: Props['settings'] }) {
    const form = useForm(settings);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(PlatformBillingController.settings.url(), {
                    preserveScroll: true,
                });
            }}
            className="flex flex-wrap items-end gap-2 text-sm"
        >
            {Object.keys(form.data.ai_weights).map((task) => (
                <label key={task} className="grid gap-0.5 text-xs">
                    {t('Credits per :task (× 100)', { task })}
                    <Input
                        type="number"
                        min={0}
                        className="w-24"
                        value={form.data.ai_weights[task]}
                        onChange={(e) =>
                            form.setData('ai_weights', {
                                ...form.data.ai_weights,
                                [task]: Number(e.target.value),
                            })
                        }
                    />
                </label>
            ))}
            <label className="grid gap-0.5 text-xs">
                {t('Buffer after credits run out (%)')}
                <Input
                    type="number"
                    min={0}
                    max={50}
                    className="w-20"
                    value={form.data.buffer_percent}
                    onChange={(e) =>
                        form.setData('buffer_percent', Number(e.target.value))
                    }
                />
            </label>
            <Button size="sm" variant="outline">
                {t('Save')}
            </Button>
        </form>
    );
}

export default function PlatformBilling({
    plans,
    packs,
    settings,
    modules,
    subscriptions,
}: Props) {
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title={t('Billing')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <div className="flex items-start justify-between">
                    <Heading
                        title={t('Billing')}
                        description={t(
                            'Plans, credit packs and how AI credits are counted.',
                        )}
                    />
                    <Button
                        variant="outline"
                        onClick={() => setAdding(!adding)}
                    >
                        {adding ? t('Close') : t('Add plan')}
                    </Button>
                </div>
                {adding && (
                    <PlanForm
                        modules={modules}
                        onDone={() => setAdding(false)}
                    />
                )}
                {plans.map((p) => (
                    <PlanForm key={p.id} plan={p} modules={modules} />
                ))}
                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Credit packs')} />
                    {packs.map((p) => (
                        <PackForm key={p.id} pack={p} />
                    ))}
                    <PackForm />
                </section>
                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('How credits are counted')}
                        description={t(
                            '100 = one credit. An assistant reply or a voice note costs 1 credit by default, a translation 0.5.',
                        )}
                    />
                    <Settings settings={settings} />
                </section>
                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Subscriptions')} />
                    <ul className="divide-y rounded-xl border text-sm">
                        {subscriptions.map((s) => (
                            <li
                                key={s.hotel_id}
                                className="flex flex-wrap items-center gap-3 px-3 py-2"
                            >
                                <Link
                                    href={PlatformBillingController.hotel(
                                        s.hotel_id,
                                    )}
                                    className="font-medium underline"
                                >
                                    {s.hotel}
                                </Link>
                                <span>{s.plan}</span>
                                <Badge variant="outline">{s.status}</Badge>
                                <span className="text-muted-foreground">
                                    {t('until :date', { date: s.period_end })}
                                </span>
                            </li>
                        ))}
                        {subscriptions.length === 0 && (
                            <li className="px-3 py-2 text-muted-foreground">
                                {t('No subscriptions yet.')}
                            </li>
                        )}
                    </ul>
                </section>
            </div>
        </>
    );
}
