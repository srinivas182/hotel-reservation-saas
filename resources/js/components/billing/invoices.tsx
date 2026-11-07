import { router } from '@inertiajs/react';
import PlatformInvoiceController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformInvoiceController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

export type InvoiceRow = {
    id: string;
    number: string;
    kind: string;
    issued_on: string;
    due_on: string;
    total: string;
    balance: string;
    balance_minor: number;
    status: string;
    days_overdue: number;
    pdf_url: string;
    payments: {
        amount: string;
        method: string;
        reference: string | null;
        on: string;
    }[];
};

const statusLabel = (s: string) =>
    ({
        open: t('To pay'),
        overdue: t('Overdue'),
        paid: t('Paid'),
        cancelled: t('Cancelled'),
        credit_note: t('Credit note'),
    })[s] ?? s;

export function Invoices({
    invoices,
    platform = false,
    methods = [],
}: {
    invoices: InvoiceRow[];
    platform?: boolean;
    methods?: string[];
}) {
    const pay = (invoice: InvoiceRow) => {
        const amount = window.prompt(
            t('Amount received'),
            String(invoice.balance_minor),
        );
        if (!amount) {
            return;
        }
        const method = window.prompt(
            t('Method (:methods)', { methods: methods.join(', ') }),
            methods[0] ?? 'other',
        );
        const reference = window.prompt(t('Reference (optional)')) ?? '';
        router.post(
            PlatformInvoiceController.payment.url(invoice.id),
            {
                amount,
                method,
                reference,
                received_on: new Date().toISOString().slice(0, 10),
            },
            { preserveScroll: true },
        );
    };
    const credit = (invoice: InvoiceRow) => {
        const amount = window.prompt(
            t('Credit note amount'),
            String(invoice.balance_minor),
        );
        const reason = amount
            ? window.prompt(t('Reason for the credit note'))
            : null;
        if (amount && reason) {
            router.post(
                PlatformInvoiceController.creditNote.url(invoice.id),
                { amount, reason },
                { preserveScroll: true },
            );
        }
    };

    return (
        <section className="flex flex-col gap-2 text-sm">
            <Heading variant="small" title={t('Invoices')} />
            {invoices.length === 0 && (
                <p className="text-muted-foreground">{t('No invoices yet.')}</p>
            )}
            <ul className="divide-y rounded-xl border">
                {invoices.map((i) => (
                    <li
                        key={i.id}
                        className="flex flex-wrap items-center gap-3 px-3 py-2"
                    >
                        <a
                            href={i.pdf_url}
                            target="_blank"
                            rel="noreferrer"
                            className="font-medium underline"
                        >
                            {i.number}
                        </a>
                        <span className="text-muted-foreground">
                            {i.issued_on}
                        </span>
                        <span>{i.total}</span>
                        <Badge
                            variant={
                                i.status === 'overdue'
                                    ? 'destructive'
                                    : i.status === 'paid'
                                      ? 'default'
                                      : 'outline'
                            }
                        >
                            {statusLabel(i.status)}
                            {i.status === 'overdue' &&
                                ` · ${t(':days d', { days: i.days_overdue })}`}
                        </Badge>
                        {i.balance_minor > 0 && (
                            <span className="text-muted-foreground">
                                {t('Due :date, :balance left', {
                                    date: i.due_on,
                                    balance: i.balance,
                                })}
                            </span>
                        )}
                        {platform &&
                            i.kind === 'invoice' &&
                            i.balance_minor > 0 && (
                                <span className="ml-auto flex gap-1">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => pay(i)}
                                    >
                                        {t('Record a payment')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => credit(i)}
                                    >
                                        {t('Credit note')}
                                    </Button>
                                </span>
                            )}
                        {i.payments.length > 0 && (
                            <span className="w-full text-xs text-muted-foreground">
                                {i.payments
                                    .map(
                                        (p) =>
                                            `${p.on} · ${p.amount} · ${p.method}${p.reference ? ` · ${p.reference}` : ''}`,
                                    )
                                    .join(' — ')}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
