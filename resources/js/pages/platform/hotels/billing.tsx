import { Head, Link, useForm } from '@inertiajs/react';
import PlatformBillingController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformBillingController';
import { CreditBars, PlanSummary } from '@/components/billing/usage';
import type { Overview } from '@/components/billing/usage';
import { BillingDetailsForm } from '@/components/billing/billing-details';
import type { BillingDetails } from '@/components/billing/billing-details';
import { Invoices } from '@/components/billing/invoices';
import type { InvoiceRow } from '@/components/billing/invoices';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import {
    DataTable,
    Field,
    formatMoney,
    PageBody,
    PageHeader,
    Section,
    Switch,
    Tabs,
    useTabs,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Props = Overview & {
    invoices: InvoiceRow[];
    entities: { id: number; name: string }[];
    billingDetails: BillingDetails;
    paymentMethods: string[];
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
    const tabs = [
        { key: 'plan', label: t('Plan and credits') },
        {
            key: 'invoicing',
            label: t('Invoicing'),
            count: props.invoices.length,
        },
        { key: 'history', label: t('History'), count: props.changes.length },
    ];
    const [tab, setTab] = useTabs(tabs);

    return (
        <>
            <Head title={`${hotel.name} · ${t('Billing')}`} />
            <PageBody>
                <PageHeader
                    title={`${hotel.name} · ${t('Billing')}`}
                    description={t(
                        'The hotel’s plan, credits, invoices and how it pays.',
                    )}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={HotelOverviewController.show(hotel.id)}>
                                {t('Hotel overview')}
                            </Link>
                        </Button>
                    }
                />
                <PlanSummary overview={props} />
                <CreditBars overview={props} />
                <Tabs tabs={tabs} active={tab} onSelect={setTab} />

                {tab === 'plan' && (
                    <>
                        <Section
                            aside
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
                        >
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
                                className="grid gap-4"
                            >
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <Field
                                        label={t('Plan')}
                                        error={errors.plan}
                                    >
                                        <select
                                            className="h-9"
                                            value={plan.data.plan_id}
                                            onChange={(e) =>
                                                plan.setData(
                                                    'plan_id',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {props.plans.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.code} (
                                                    {p.currencies.join(', ')})
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    {!props.subscription && (
                                        <>
                                            <Field label={t('Interval')}>
                                                <select
                                                    className="h-9"
                                                    value={plan.data.interval}
                                                    onChange={(e) =>
                                                        plan.setData(
                                                            'interval',
                                                            e.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="monthly">
                                                        {t('Monthly')}
                                                    </option>
                                                    <option value="yearly">
                                                        {t('Yearly')}
                                                    </option>
                                                </select>
                                            </Field>
                                            <Field label={t('Currency')}>
                                                <Input
                                                    value={plan.data.currency}
                                                    onChange={(e) =>
                                                        plan.setData(
                                                            'currency',
                                                            e.target.value.toUpperCase(),
                                                        )
                                                    }
                                                />
                                            </Field>
                                        </>
                                    )}
                                </div>
                                {!props.subscription && (
                                    <Switch
                                        checked={plan.data.trial}
                                        onChange={(v) =>
                                            plan.setData('trial', v)
                                        }
                                        label={t('Start with a trial')}
                                    />
                                )}
                                <div className="flex justify-end">
                                    <Button disabled={plan.processing}>
                                        {props.subscription
                                            ? t('Change plan')
                                            : t('Start')}
                                    </Button>
                                </div>
                            </form>
                        </Section>

                        {props.subscription?.status === 'trialing' && (
                            <Section
                                aside
                                title={t('Extend the trial')}
                                description={t(
                                    'Adds days to the end of the trial.',
                                )}
                            >
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
                                    className="flex flex-wrap items-end gap-3"
                                >
                                    <Field label={t('Days')} className="w-32">
                                        <Input
                                            type="number"
                                            min={1}
                                            max={60}
                                            value={trial.data.days}
                                            onChange={(e) =>
                                                trial.setData(
                                                    'days',
                                                    Number(e.target.value),
                                                )
                                            }
                                        />
                                    </Field>
                                    <Button variant="outline">
                                        {t('Extend the trial')}
                                    </Button>
                                </form>
                            </Section>
                        )}

                        {props.subscription && (
                            <Section
                                aside
                                title={t('Credits')}
                                description={t(
                                    'Correct the balance, or add a pack sold outside the app (invoiced in the next step).',
                                )}
                            >
                                <div className="grid gap-6 lg:grid-cols-2">
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            adjust.post(
                                                PlatformBillingController.adjust.url(
                                                    hotel.id,
                                                ),
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        adjust.reset(),
                                                },
                                            );
                                        }}
                                        className="grid content-start gap-3"
                                    >
                                        <h3 className="text-sm font-medium">
                                            {t('Adjust credits')}
                                        </h3>
                                        <div className="grid grid-cols-2 gap-3">
                                            <Field label={t('Kind')}>
                                                <select
                                                    className="h-9"
                                                    value={adjust.data.kind}
                                                    onChange={(e) =>
                                                        adjust.setData(
                                                            'kind',
                                                            e.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="ai">
                                                        {t('AI credits')}
                                                    </option>
                                                    <option value="email">
                                                        {t('Email credits')}
                                                    </option>
                                                </select>
                                            </Field>
                                            <Field
                                                label={t(
                                                    'Credits (negative to remove)',
                                                )}
                                                error={errors.credits}
                                            >
                                                <Input
                                                    type="number"
                                                    value={adjust.data.credits}
                                                    onChange={(e) =>
                                                        adjust.setData(
                                                            'credits',
                                                            Number(
                                                                e.target.value,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </Field>
                                        </div>
                                        <Field
                                            label={t('Reason')}
                                            error={errors.reason}
                                        >
                                            <Input
                                                value={adjust.data.reason}
                                                onChange={(e) =>
                                                    adjust.setData(
                                                        'reason',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <div>
                                            <Button
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
                                        className="grid content-start gap-3"
                                    >
                                        <h3 className="text-sm font-medium">
                                            {t('Add a credit pack')}
                                        </h3>
                                        <Field label={t('Pack')}>
                                            <select
                                                className="h-9"
                                                value={pack.data.pack_id}
                                                onChange={(e) =>
                                                    pack.setData(
                                                        'pack_id',
                                                        Number(e.target.value),
                                                    )
                                                }
                                            >
                                                {props.packsForSale.map((p) => (
                                                    <option
                                                        key={p.id}
                                                        value={p.id}
                                                    >
                                                        {p.code}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        <div>
                                            <Button
                                                variant="outline"
                                                disabled={
                                                    pack.processing ||
                                                    props.packsForSale
                                                        .length === 0
                                                }
                                            >
                                                {t('Add')}
                                            </Button>
                                        </div>
                                    </form>
                                </div>
                            </Section>
                        )}
                    </>
                )}

                {tab === 'invoicing' && (
                    <>
                        <BillingDetailsForm
                            hotelId={hotel.id}
                            details={props.billingDetails}
                            entities={props.entities}
                        />
                        <Invoices
                            invoices={props.invoices}
                            platform
                            methods={props.paymentMethods}
                        />
                    </>
                )}

                {tab === 'history' && (
                    <Section flush title={t('History')}>
                        <DataTable
                            rows={props.changes.map((c, i) => ({ ...c, i }))}
                            rowKey={(c) => c.i}
                            empty={t('Nothing yet.')}
                            columns={[
                                {
                                    key: 'kind',
                                    header: t('Change'),
                                    cell: (c) => (
                                        <span className="font-mono text-xs">
                                            {c.kind}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'amount',
                                    header: t('Amount'),
                                    align: 'right',
                                    cell: (c) =>
                                        c.amount_minor > 0
                                            ? formatMoney(
                                                  c.amount_minor,
                                                  c.currency,
                                              )
                                            : '—',
                                },
                                {
                                    key: 'invoiced',
                                    header: t('Status'),
                                    cell: (c) =>
                                        c.invoiced ? (
                                            <Badge variant="outline">
                                                {t('invoiced')}
                                            </Badge>
                                        ) : (
                                            '—'
                                        ),
                                },
                                {
                                    key: 'at',
                                    header: t('When'),
                                    cell: (c) => (
                                        <span className="text-xs text-muted-foreground">
                                            {c.at}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}
            </PageBody>
        </>
    );
}
