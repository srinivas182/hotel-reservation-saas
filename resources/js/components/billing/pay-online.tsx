import { router } from '@inertiajs/react';
import { useState } from 'react';
import HotelPaymentController from '@/actions/App/Domains/Billing/Http/Controllers/HotelPaymentController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { InvoiceRow } from './invoices';

type GatewayOption = { id: number; name: string; test: boolean };
type Pack = {
    id: number;
    name: string;
    kind: string;
    credits: number;
    price: string;
};
type Plan = { id: string; name: string; monthly: string | null };

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export function PayOnline({
    invoices,
    packs,
    gateways,
    plans,
    currentPlanId,
}: {
    invoices: InvoiceRow[];
    packs: Pack[];
    gateways: GatewayOption[];
    plans: Plan[];
    currentPlanId: string | null;
}) {
    const [gateway, setGateway] = useState<number | null>(
        gateways[0]?.id ?? null,
    );
    const [plan, setPlan] = useState(currentPlanId ?? '');
    const due = invoices.filter(
        (i) => i.kind === 'invoice' && i.balance_minor > 0,
    );

    return (
        <section className="flex flex-col gap-3 rounded-xl border p-3 text-sm">
            <Heading variant="small" title={t('Pay and buy online')} />
            {gateways.length === 0 ? (
                <p className="text-muted-foreground">
                    {t(
                        'Online payment is not available yet: pay by transfer or mobile money using the details on the invoice.',
                    )}
                </p>
            ) : (
                <>
                    <label className="flex items-center gap-2">
                        {t('Pay with')}
                        <select
                            className={selectClass}
                            value={gateway ?? ''}
                            onChange={(e) => setGateway(Number(e.target.value))}
                        >
                            {gateways.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.name}
                                    {g.test ? ` (${t('test')})` : ''}
                                </option>
                            ))}
                        </select>
                    </label>
                    {due.map((i) => (
                        <div
                            key={i.id}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span>
                                {i.number} · {i.balance}
                            </span>
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        HotelPaymentController.payInvoice.url(
                                            i.id,
                                        ),
                                        { gateway_id: gateway },
                                    )
                                }
                            >
                                {t('Pay now')}
                            </Button>
                        </div>
                    ))}
                    {packs.map((p) => (
                        <div
                            key={p.id}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span>
                                {p.name} · {p.price}
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        HotelPaymentController.buyPack.url(
                                            p.id,
                                        ),
                                        { gateway_id: gateway },
                                    )
                                }
                            >
                                {t('Buy')}
                            </Button>
                        </div>
                    ))}
                </>
            )}
            {plans.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 border-t pt-3">
                    <span>{t('Change plan')}</span>
                    <select
                        className={selectClass}
                        value={plan}
                        onChange={(e) => setPlan(e.target.value)}
                    >
                        {plans.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                                {p.monthly ? ` · ${p.monthly}` : ''}
                            </option>
                        ))}
                    </select>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={plan === '' || plan === currentPlanId}
                        onClick={() =>
                            window.confirm(
                                t(
                                    'Upgrades apply now (pro rata); downgrades at the end of the period.',
                                ),
                            ) &&
                            router.put(
                                HotelPaymentController.changePlan.url(),
                                { plan_id: plan },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('Change')}
                    </Button>
                </div>
            )}
        </section>
    );
}
