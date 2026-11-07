import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FolioController from '@/actions/App/Domains/Folio/Http/Controllers/FolioController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

export type Folio = {
    lines: {
        id: number | null;
        type: string;
        description: string;
        quantity: number;
        amount: string;
        reason: string | null;
        method_label: string | null;
        reference: string | null;
        by: string | null;
        at: string | null;
        corrected: boolean;
        is_correction: boolean;
    }[];
    charges: string;
    payments: string;
    balance: string;
    balance_minor: number;
    statement_url: string;
    methods: { value: string; label: string }[];
    can_charge: boolean;
    can_pay: boolean;
    currency: string;
    charge_url?: string;
    pay_url?: string;
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export function RoomBill({
    bookingId,
    folio,
    title,
}: {
    bookingId: string;
    title?: string;
    folio: Folio;
}) {
    const [panel, setPanel] = useState<'charge' | 'payment' | null>(null);
    const charge = useForm({
        description: '',
        quantity: 1,
        unit_price: '',
        tax_scope: '',
    });
    const payment = useForm({
        amount: folio.balance_minor > 0 ? '' : '',
        method: folio.methods[0]?.value ?? 'cash',
        reference: '',
    });

    return (
        <section className="flex flex-col gap-2 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Heading variant="small" title={title ?? t('Room bill')} />
                <div className="flex gap-2">
                    {folio.can_charge && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setPanel(panel === 'charge' ? null : 'charge')
                            }
                        >
                            {t('Add a charge')}
                        </Button>
                    )}
                    {folio.can_pay && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setPanel(panel === 'payment' ? null : 'payment')
                            }
                        >
                            {t('Record a payment')}
                        </Button>
                    )}
                    <a
                        href={folio.statement_url}
                        target="_blank"
                        rel="noreferrer"
                        className="self-center underline"
                    >
                        {t('Print statement')}
                    </a>
                </div>
            </div>

            {panel === 'charge' && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        charge.transform((d) => ({
                            ...d,
                            tax_scope: d.tax_scope === '' ? null : d.tax_scope,
                        }));
                        charge.post(
                            folio.charge_url ??
                                FolioController.charge.url(bookingId),
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    charge.reset();
                                    setPanel(null);
                                },
                            },
                        );
                    }}
                    className="flex flex-wrap items-end gap-2 rounded-lg border p-3"
                >
                    <Input
                        aria-label={t('Description')}
                        placeholder={t('e.g. Minibar, laundry')}
                        className="w-56"
                        value={charge.data.description}
                        onChange={(e) =>
                            charge.setData('description', e.target.value)
                        }
                    />
                    <Input
                        aria-label={t('Quantity')}
                        type="number"
                        min={1}
                        className="w-20"
                        value={charge.data.quantity}
                        onChange={(e) =>
                            charge.setData('quantity', Number(e.target.value))
                        }
                    />
                    <Input
                        aria-label={t('Unit price (:currency)', {
                            currency: folio.currency,
                        })}
                        placeholder={t('Unit price (:currency)', {
                            currency: folio.currency,
                        })}
                        type="number"
                        min={0}
                        step="any"
                        className="w-36"
                        value={charge.data.unit_price}
                        onChange={(e) =>
                            charge.setData('unit_price', e.target.value)
                        }
                    />
                    <select
                        aria-label={t('Taxes')}
                        className={selectClass}
                        value={charge.data.tax_scope}
                        onChange={(e) =>
                            charge.setData('tax_scope', e.target.value)
                        }
                    >
                        <option value="">{t('No tax added')}</option>
                        <option value="food_beverage">
                            {t('Food and drink taxes')}
                        </option>
                        <option value="rooms">{t('Room taxes')}</option>
                    </select>
                    <Button size="sm" disabled={charge.processing}>
                        {t('Add')}
                    </Button>
                    <InputError
                        message={
                            charge.errors.description ??
                            charge.errors.unit_price
                        }
                    />
                </form>
            )}

            {panel === 'payment' && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        payment.post(
                            folio.pay_url ?? FolioController.pay.url(bookingId),
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    payment.reset();
                                    setPanel(null);
                                },
                            },
                        );
                    }}
                    className="flex flex-wrap items-end gap-2 rounded-lg border p-3"
                >
                    <Input
                        aria-label={t('Amount (:currency)', {
                            currency: folio.currency,
                        })}
                        placeholder={t('Amount (:currency)', {
                            currency: folio.currency,
                        })}
                        type="number"
                        min={0}
                        step="any"
                        className="w-36"
                        value={payment.data.amount}
                        onChange={(e) =>
                            payment.setData('amount', e.target.value)
                        }
                    />
                    <select
                        aria-label={t('Method')}
                        className={selectClass}
                        value={payment.data.method}
                        onChange={(e) =>
                            payment.setData('method', e.target.value)
                        }
                    >
                        {folio.methods.map((m) => (
                            <option key={m.value} value={m.value}>
                                {m.label}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label={t('Reference')}
                        placeholder={t('Reference (transaction number…)')}
                        className="w-56"
                        value={payment.data.reference}
                        onChange={(e) =>
                            payment.setData('reference', e.target.value)
                        }
                    />
                    <Button size="sm" disabled={payment.processing}>
                        {t('Record')}
                    </Button>
                    <InputError
                        message={payment.errors.amount ?? payment.errors.method}
                    />
                </form>
            )}

            <ul className="divide-y rounded-xl border">
                {folio.lines.map((line, i) => (
                    <li
                        key={line.id ?? `n${i}`}
                        className={
                            line.corrected
                                ? 'flex flex-wrap items-center gap-3 px-3 py-2 text-muted-foreground line-through'
                                : 'flex flex-wrap items-center gap-3 px-3 py-2'
                        }
                    >
                        <span className="w-24 text-xs text-muted-foreground">
                            {line.at?.slice(0, 10)}
                        </span>
                        <span className="min-w-48 flex-1">
                            {line.description}
                            {line.quantity > 1 && ` × ${line.quantity}`}
                            {line.reference && (
                                <span className="text-xs text-muted-foreground">
                                    {' '}
                                    · {line.reference}
                                </span>
                            )}
                            {line.reason && (
                                <span className="block text-xs text-muted-foreground">
                                    {line.reason}
                                </span>
                            )}
                        </span>
                        <span
                            className={
                                line.type === 'payment'
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : ''
                            }
                        >
                            {line.type === 'payment' ? '−' : ''}
                            {line.amount}
                        </span>
                        {line.id !== null &&
                            !line.corrected &&
                            !line.is_correction &&
                            ((line.type === 'payment' && folio.can_pay) ||
                                (line.type !== 'payment' &&
                                    folio.can_charge)) && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        const reason = window.prompt(
                                            t('Why correct this line?'),
                                        );
                                        if (reason) {
                                            router.post(
                                                FolioController.correct.url(
                                                    line.id as number,
                                                ),
                                                { reason },
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    {t('Correct')}
                                </Button>
                            )}
                    </li>
                ))}
                <li className="flex justify-between px-3 py-2 font-medium">
                    <span>
                        {t('Charges :charges · Payments :payments', {
                            charges: folio.charges,
                            payments: folio.payments,
                        })}
                    </span>
                    <span
                        className={
                            folio.balance_minor > 0 ? 'text-destructive' : ''
                        }
                    >
                        {t('Balance due: :balance', { balance: folio.balance })}
                    </span>
                </li>
            </ul>
        </section>
    );
}
