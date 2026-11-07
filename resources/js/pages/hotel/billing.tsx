import { Head } from '@inertiajs/react';
import BillingController from '@/actions/App/Domains/Billing/Http/Controllers/BillingController';
import { CreditBars, PlanSummary } from '@/components/billing/usage';
import type { Overview } from '@/components/billing/usage';
import { Invoices } from '@/components/billing/invoices';
import type { InvoiceRow } from '@/components/billing/invoices';
import Heading from '@/components/heading';
import { t } from '@/lib/i18n';

type Props = Overview & {
    invoices: InvoiceRow[];
    reduced: boolean;
    plans: {
        id: string;
        code: string;
        name: string;
        monthly: string | null;
        ai_credits: number;
        email_credits: number;
        staff_limit: number | null;
        modules: string[];
    }[];
    packsForSale: {
        id: number;
        name: string;
        kind: string;
        credits: number;
        price: string;
    }[];
};

export default function Billing(props: Props) {
    return (
        <>
            <Head title={t('Plan and credits')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading
                    title={t('Plan and credits')}
                    description={t(
                        'When AI credits run out, the assistant passes guests to your team until you add credits. Security emails are always sent.',
                    )}
                />
                <PlanSummary overview={props} />
                <CreditBars overview={props} />

                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('Plans')}
                        description={t(
                            'To change plan or buy credits, contact us (online payment is coming).',
                        )}
                    />
                    <div className="grid gap-3 sm:grid-cols-3">
                        {props.plans.map((p) => (
                            <div
                                key={p.id}
                                className={
                                    props.subscription?.plan_id === p.id
                                        ? 'rounded-xl border-2 border-primary p-4 text-sm'
                                        : 'rounded-xl border p-4 text-sm'
                                }
                            >
                                <div className="text-lg font-semibold">
                                    {p.name}
                                </div>
                                <div>
                                    {p.monthly
                                        ? t(':price / month', {
                                              price: p.monthly,
                                          })
                                        : '—'}
                                </div>
                                <ul className="mt-2 text-muted-foreground">
                                    <li>
                                        {t(':credits AI credits / month', {
                                            credits: p.ai_credits,
                                        })}
                                    </li>
                                    <li>
                                        {t(':credits email credits / month', {
                                            credits: p.email_credits,
                                        })}
                                    </li>
                                    <li>
                                        {p.staff_limit === null
                                            ? t('Unlimited staff accounts')
                                            : t('Up to :limit staff accounts', {
                                                  limit: p.staff_limit,
                                              })}
                                    </li>
                                    {p.modules.map((m) => (
                                        <li key={m}>✓ {m}</li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </section>

                {props.packsForSale.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <Heading
                            variant="small"
                            title={t('Credit packs (valid 12 months)')}
                        />
                        <ul className="divide-y rounded-xl border text-sm">
                            {props.packsForSale.map((p) => (
                                <li
                                    key={p.id}
                                    className="flex justify-between px-3 py-2"
                                >
                                    <span>{p.name}</span>
                                    <span>{p.price}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <Invoices invoices={props.invoices} />
            </div>
        </>
    );
}

Billing.layout = {
    breadcrumbs: [{ title: 'Plan and credits', href: BillingController() }],
};
