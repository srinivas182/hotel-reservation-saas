import { router, useForm } from '@inertiajs/react';
import PlatformInvoiceController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformInvoiceController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

export type BillingDetails = {
    billing_entity_id: number | null;
    name?: string;
    address?: string;
    tax_id?: string;
    email?: string;
    grace_until: string | null;
    reduced: boolean;
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export function BillingDetailsForm({
    hotelId,
    details,
    entities,
}: {
    hotelId: string;
    details: BillingDetails;
    entities: { id: number; name: string }[];
}) {
    const form = useForm({
        billing_entity_id: details.billing_entity_id ?? '',
        name: details.name ?? '',
        address: details.address ?? '',
        tax_id: details.tax_id ?? '',
        email: details.email ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <section className="flex flex-col gap-2 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Heading variant="small" title={t('Invoicing')} />
                {details.reduced && (
                    <Badge variant="destructive">{t('Reduced mode')}</Badge>
                )}
                {details.grace_until && (
                    <Badge variant="outline">
                        {t('Delay until :date', { date: details.grace_until })}
                    </Badge>
                )}
            </div>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({
                        ...d,
                        billing_entity_id:
                            d.billing_entity_id === ''
                                ? null
                                : Number(d.billing_entity_id),
                    }));
                    form.put(PlatformInvoiceController.details.url(hotelId), {
                        preserveScroll: true,
                    });
                }}
                className="grid gap-2 rounded-xl border p-3 sm:grid-cols-2"
            >
                <select
                    aria-label={t('Issuing company')}
                    className={selectClass}
                    value={form.data.billing_entity_id}
                    onChange={(e) =>
                        form.setData('billing_entity_id', e.target.value)
                    }
                >
                    <option value="">{t('Default issuing company')}</option>
                    {entities.map((en) => (
                        <option key={en.id} value={en.id}>
                            {en.name}
                        </option>
                    ))}
                </select>
                <Input
                    aria-label={t('Invoice name')}
                    placeholder={t('Invoice name (company)')}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <Input
                    aria-label={t('Address')}
                    placeholder={t('Address')}
                    value={form.data.address}
                    onChange={(e) => form.setData('address', e.target.value)}
                />
                <Input
                    aria-label={t('Tax ID')}
                    placeholder={t('Tax ID')}
                    value={form.data.tax_id}
                    onChange={(e) => form.setData('tax_id', e.target.value)}
                />
                <Input
                    aria-label={t('Email')}
                    placeholder={t('Invoices sent to (email)')}
                    type="email"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
                <div className="flex gap-2">
                    <Button size="sm" disabled={form.processing}>
                        {t('Save')}
                    </Button>
                    <Button
                        size="sm"
                        type="button"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                PlatformInvoiceController.issueNow.url(hotelId),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('Invoice now')}
                    </Button>
                    <Button
                        size="sm"
                        type="button"
                        variant="ghost"
                        onClick={() => {
                            const until = window.prompt(
                                t('Delay payment until (YYYY-MM-DD)'),
                            );
                            const reason = until
                                ? window.prompt(t('Reason'))
                                : null;
                            if (until && reason) {
                                router.post(
                                    PlatformInvoiceController.delay.url(
                                        hotelId,
                                    ),
                                    { until, reason },
                                    { preserveScroll: true },
                                );
                            }
                        }}
                    >
                        {t('Grant a delay')}
                    </Button>
                </div>
                <InputError
                    message={errors.invoice ?? errors.billing_entity_id}
                />
            </form>
        </section>
    );
}
