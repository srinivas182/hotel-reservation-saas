import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformInvoiceController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformInvoiceController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

export type Entity = {
    id: number;
    name: string;
    legal_name: string;
    country_code: string;
    address: string;
    registration: string | null;
    tax_id: string | null;
    email: string;
    phone: string | null;
    invoice_prefix: string;
    tax_rules: { name: string; rate: number }[];
    prices_include_tax: boolean;
    payment_terms_days: number;
    payment_instructions: string | null;
    footer: string | null;
    is_default: boolean;
    active: boolean;
};
export type Report = {
    mrr: Record<string, string>;
    overdue: Record<string, string>;
    hotels: {
        hotel_id: string;
        hotel: string | null;
        plan: string | null;
        status: string;
        mrr: string;
        ai_cost_usd: string;
        overdue: string | null;
    }[];
};

const blank: Omit<Entity, 'id'> = {
    name: '',
    legal_name: '',
    country_code: 'CI',
    address: '',
    registration: '',
    tax_id: '',
    email: '',
    phone: '',
    invoice_prefix: 'CNH',
    tax_rules: [{ name: 'TVA', rate: 18 }],
    prices_include_tax: false,
    payment_terms_days: 7,
    payment_instructions: '',
    footer: '',
    is_default: true,
    active: true,
};

function EntityForm({
    entity,
    onDone,
}: {
    entity?: Entity;
    onDone?: () => void;
}) {
    const form = useForm<Omit<Entity, 'id'>>(entity ?? blank);
    const errors = form.errors as Record<string, string | undefined>;
    const text = (
        key: keyof Omit<Entity, 'id'>,
        label: string,
        type = 'text',
    ) => (
        <label className="grid gap-0.5 text-xs">
            {label}
            <Input
                type={type}
                value={(form.data[key] as string | null) ?? ''}
                onChange={(e) => form.setData(key, e.target.value as never)}
            />
            <InputError message={errors[key]} />
        </label>
    );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                const options = {
                    preserveScroll: true,
                    onSuccess: () => onDone?.(),
                };
                if (entity) {
                    form.put(
                        PlatformInvoiceController.updateEntity.url(entity.id),
                        options,
                    );
                } else {
                    form.post(
                        PlatformInvoiceController.storeEntity.url(),
                        options,
                    );
                }
            }}
            className="grid gap-2 rounded-xl border p-3 text-sm sm:grid-cols-3"
        >
            {text('name', t('Name'))}
            {text('legal_name', t('Legal name'))}
            {text('country_code', t('Country (2 letters)'))}
            {text('registration', t('Registration (RCCM)'))}
            {text('tax_id', t('Tax ID (NCC)'))}
            {text('invoice_prefix', t('Invoice prefix'))}
            {text('email', t('Email'), 'email')}
            {text('phone', t('Phone'))}
            <label className="grid gap-0.5 text-xs">
                {t('Payment terms (days)')}
                <Input
                    type="number"
                    min={0}
                    value={form.data.payment_terms_days}
                    onChange={(e) =>
                        form.setData(
                            'payment_terms_days',
                            Number(e.target.value),
                        )
                    }
                />
            </label>
            <label className="grid gap-0.5 text-xs sm:col-span-3">
                {t('Address')}
                <textarea
                    className="min-h-14 rounded-md border border-input bg-transparent px-3 py-2"
                    value={form.data.address}
                    onChange={(e) => form.setData('address', e.target.value)}
                />
            </label>
            <div className="flex flex-wrap items-end gap-2 sm:col-span-3">
                {form.data.tax_rules.map((r, i) => (
                    <span key={i} className="flex items-end gap-1">
                        <Input
                            aria-label={t('Tax name')}
                            className="w-28"
                            value={r.name}
                            onChange={(e) =>
                                form.setData(
                                    'tax_rules',
                                    form.data.tax_rules.map((x, j) =>
                                        j === i
                                            ? { ...x, name: e.target.value }
                                            : x,
                                    ),
                                )
                            }
                        />
                        <Input
                            aria-label={t('Rate (%)')}
                            type="number"
                            step="any"
                            className="w-20"
                            value={r.rate}
                            onChange={(e) =>
                                form.setData(
                                    'tax_rules',
                                    form.data.tax_rules.map((x, j) =>
                                        j === i
                                            ? {
                                                  ...x,
                                                  rate: Number(e.target.value),
                                              }
                                            : x,
                                    ),
                                )
                            }
                        />
                        <span>%</span>
                    </span>
                ))}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        form.setData('tax_rules', [
                            ...form.data.tax_rules,
                            { name: '', rate: 0 },
                        ])
                    }
                >
                    {t('Add a tax')}
                </Button>
                <label className="flex items-center gap-1 text-xs">
                    <input
                        type="checkbox"
                        checked={form.data.prices_include_tax}
                        onChange={(e) =>
                            form.setData('prices_include_tax', e.target.checked)
                        }
                    />
                    {t('Plan prices include tax')}
                </label>
            </div>
            <label className="grid gap-0.5 text-xs sm:col-span-3">
                {t('How to pay (bank, mobile money…)')}
                <textarea
                    className="min-h-14 rounded-md border border-input bg-transparent px-3 py-2"
                    value={form.data.payment_instructions ?? ''}
                    onChange={(e) =>
                        form.setData('payment_instructions', e.target.value)
                    }
                />
            </label>
            <label className="grid gap-0.5 text-xs sm:col-span-3">
                {t('Footer (legal mentions)')}
                <textarea
                    className="min-h-10 rounded-md border border-input bg-transparent px-3 py-2"
                    value={form.data.footer ?? ''}
                    onChange={(e) => form.setData('footer', e.target.value)}
                />
            </label>
            <label className="flex items-center gap-1 text-xs">
                <input
                    type="checkbox"
                    checked={form.data.is_default}
                    onChange={(e) =>
                        form.setData('is_default', e.target.checked)
                    }
                />
                {t('Default issuing company')}
            </label>
            <label className="flex items-center gap-1 text-xs">
                <input
                    type="checkbox"
                    checked={form.data.active}
                    onChange={(e) => form.setData('active', e.target.checked)}
                />
                {t('Active')}
            </label>
            <div>
                <Button size="sm" disabled={form.processing}>
                    {entity ? t('Save') : t('Add issuing company')}
                </Button>
            </div>
        </form>
    );
}

export function Entities({
    entities,
    report,
}: {
    entities: Entity[];
    report: Report;
}) {
    const [adding, setAdding] = useState(entities.length === 0);

    return (
        <>
            <section className="flex flex-col gap-2 text-sm">
                <Heading variant="small" title={t('Revenue')} />
                <div className="flex flex-wrap gap-4">
                    {Object.entries(report.mrr).map(([c, v]) => (
                        <span key={c}>
                            {t('Monthly revenue :currency: :amount', {
                                currency: c,
                                amount: v,
                            })}
                        </span>
                    ))}
                    {Object.entries(report.overdue).map(([c, v]) => (
                        <Badge key={c} variant="destructive">
                            {t('Overdue :currency: :amount', {
                                currency: c,
                                amount: v,
                            })}
                        </Badge>
                    ))}
                </div>
                <table className="w-full text-left">
                    <thead className="text-xs text-muted-foreground">
                        <tr>
                            <th>{t('Hotel')}</th>
                            <th>{t('Plan')}</th>
                            <th>{t('Status')}</th>
                            <th>{t('Monthly')}</th>
                            <th>{t('AI cost this month (US$)')}</th>
                            <th>{t('Overdue')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {report.hotels.map((h) => (
                            <tr key={h.hotel_id} className="border-t">
                                <td className="py-1">{h.hotel}</td>
                                <td>{h.plan}</td>
                                <td>{h.status}</td>
                                <td>{h.mrr}</td>
                                <td>{h.ai_cost_usd}</td>
                                <td className="text-destructive">
                                    {h.overdue}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
            <section className="flex flex-col gap-2 text-sm">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={t('Issuing companies')}
                        description={t(
                            'The companies whose name, numbering and taxes appear on invoices.',
                        )}
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setAdding(!adding)}
                    >
                        {adding ? t('Close') : t('Add issuing company')}
                    </Button>
                </div>
                {adding && <EntityForm onDone={() => setAdding(false)} />}
                {entities.map((e) => (
                    <details key={e.id} className="rounded-xl border p-2">
                        <summary className="cursor-pointer">
                            {e.name} · {e.invoice_prefix}{' '}
                            {e.is_default && (
                                <Badge variant="secondary">
                                    {t('Default')}
                                </Badge>
                            )}
                        </summary>
                        <EntityForm entity={e} />
                    </details>
                ))}
            </section>
        </>
    );
}
