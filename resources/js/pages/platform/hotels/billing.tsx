import { Head, useForm } from '@inertiajs/react';
import PlatformBillingController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformBillingController';
import { CreditBars, PlanSummary } from '@/components/billing/usage';
import type { Overview } from '@/components/billing/usage';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Props = Overview & {
    hotel: { id: string; name: string; currency: string };
    plans: { id: string; code: string; currencies: string[] }[];
    packsForSale: { id: number; code: string; kind: string; credits: number }[];
    changes: {
        kind: string;
        amount_minor: number;
        currency: string;
        at: string | null;
        invoiced: boolean;
    }[];
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function HotelBilling(props: Props) {
    const { hotel } = props;
    const plan = useForm({
        plan_id: props.subscription?.plan_id ?? props.plans[0]?.id ?? '',
        interval: props.subscription?.interval ?? 'monthly',
        currency: props.subscription?.currency ?? hotel.currency,
        trial: !props.subscription,
    });
    const adjust = useForm({ kind: 'ai', credits: 0, reason: '' });
    const pack = useForm({ pack_id: props.packsForSale[0]?.id ?? 0 });
    const trial = useForm({ days: 7 });
    const errors = {
        ...(plan.errors as Record<string, string | undefined>),
        ...(adjust.errors as Record<string, string | undefined>),
    };

    return (
        <>
            <Head title={`${hotel.name} · ${t('Billing')}`} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading title={`${hotel.name} · ${t('Billing')}`} />
                <PlanSummary overview={props} />
                <CreditBars overview={props} />

                <section className="flex flex-col gap-3 rounded-xl border p-4 text-sm">
                    <Heading
                        variant="small"
                        title={
                            props.subscription
                                ? t('Change plan')
                                : t('Start a subscription')
                        }
                        description={
                            props.subscription
                                ? t(
                                      'Upgrades apply now (pro rata); downgrades at the end of the period.',
                                  )
                                : undefined
                        }
                    />
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            plan.post(
                                PlatformBillingController.subscribe.url(
                                    hotel.id,
                                ),
                                { preserveScroll: true },
                            );
                        }}
                        className="flex flex-wrap items-end gap-2"
                    >
                        <select
                            aria-label={t('Plan')}
                            className={selectClass}
                            value={plan.data.plan_id}
                            onChange={(e) =>
                                plan.setData('plan_id', e.target.value)
                            }
                        >
                            {props.plans.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.code} ({p.currencies.join(', ')})
                                </option>
                            ))}
                        </select>
                        {!props.subscription && (
                            <>
                                <select
                                    aria-label={t('Interval')}
                                    className={selectClass}
                                    value={plan.data.interval}
                                    onChange={(e) =>
                                        plan.setData('interval', e.target.value)
                                    }
                                >
                                    <option value="monthly">
                                        {t('Monthly')}
                                    </option>
                                    <option value="yearly">
                                        {t('Yearly')}
                                    </option>
                                </select>
                                <Input
                                    aria-label={t('Currency')}
                                    className="w-20"
                                    value={plan.data.currency}
                                    onChange={(e) =>
                                        plan.setData(
                                            'currency',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                />
                                <label className="flex items-center gap-1">
                                    <input
                                        type="checkbox"
                                        checked={plan.data.trial}
                                        onChange={(e) =>
                                            plan.setData(
                                                'trial',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    {t('Start with a trial')}
                                </label>
                            </>
                        )}
                        <Button size="sm" disabled={plan.processing}>
                            {props.subscription ? t('Change plan') : t('Start')}
                        </Button>
                    </form>
                    <InputError message={errors.plan} />
                    {props.subscription?.status === 'trialing' && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                trial.post(
                                    PlatformBillingController.extendTrial.url(
                                        hotel.id,
                                    ),
                                    { preserveScroll: true },
                                );
                            }}
                            className="flex items-end gap-2"
                        >
                            <Input
                                type="number"
                                min={1}
                                max={60}
                                aria-label={t('Days')}
                                className="w-20"
                                value={trial.data.days}
                                onChange={(e) =>
                                    trial.setData(
                                        'days',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <Button size="sm" variant="outline">
                                {t('Extend the trial')}
                            </Button>
                        </form>
                    )}
                </section>

                {props.subscription && (
                    <section className="grid gap-3 rounded-xl border p-4 text-sm sm:grid-cols-2">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                adjust.post(
                                    PlatformBillingController.adjust.url(
                                        hotel.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => adjust.reset(),
                                    },
                                );
                            }}
                            className="flex flex-col gap-2"
                        >
                            <Heading
                                variant="small"
                                title={t('Adjust credits')}
                            />
                            <div className="flex gap-2">
                                <select
                                    aria-label={t('Kind')}
                                    className={selectClass}
                                    value={adjust.data.kind}
                                    onChange={(e) =>
                                        adjust.setData('kind', e.target.value)
                                    }
                                >
                                    <option value="ai">
                                        {t('AI credits')}
                                    </option>
                                    <option value="email">
                                        {t('Email credits')}
                                    </option>
                                </select>
                                <Input
                                    type="number"
                                    aria-label={t(
                                        'Credits (negative to remove)',
                                    )}
                                    className="w-28"
                                    value={adjust.data.credits}
                                    onChange={(e) =>
                                        adjust.setData(
                                            'credits',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </div>
                            <Input
                                aria-label={t('Reason')}
                                placeholder={t('Reason')}
                                value={adjust.data.reason}
                                onChange={(e) =>
                                    adjust.setData('reason', e.target.value)
                                }
                            />
                            <InputError
                                message={errors.reason ?? errors.credits}
                            />
                            <div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={adjust.processing}
                                >
                                    {t('Adjust')}
                                </Button>
                            </div>
                        </form>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                pack.post(
                                    PlatformBillingController.addPack.url(
                                        hotel.id,
                                    ),
                                    { preserveScroll: true },
                                );
                            }}
                            className="flex flex-col gap-2"
                        >
                            <Heading
                                variant="small"
                                title={t('Add a credit pack')}
                                description={t(
                                    'Sold outside the app (invoiced in the next step).',
                                )}
                            />
                            <select
                                aria-label={t('Pack')}
                                className={selectClass}
                                value={pack.data.pack_id}
                                onChange={(e) =>
                                    pack.setData(
                                        'pack_id',
                                        Number(e.target.value),
                                    )
                                }
                            >
                                {props.packsForSale.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.code}
                                    </option>
                                ))}
                            </select>
                            <div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        pack.processing ||
                                        props.packsForSale.length === 0
                                    }
                                >
                                    {t('Add')}
                                </Button>
                            </div>
                        </form>
                    </section>
                )}

                <section className="flex flex-col gap-2 text-sm">
                    <Heading variant="small" title={t('History')} />
                    <ul className="divide-y rounded-xl border">
                        {props.changes.map((c, i) => (
                            <li
                                key={i}
                                className="flex justify-between px-3 py-1.5"
                            >
                                <span>
                                    {c.at} · {c.kind}
                                </span>
                                <span className="text-muted-foreground">
                                    {c.amount_minor > 0
                                        ? `${c.amount_minor} ${c.currency}`
                                        : ''}{' '}
                                    {c.invoiced ? t('invoiced') : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
