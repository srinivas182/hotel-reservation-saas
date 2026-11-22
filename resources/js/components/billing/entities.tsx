import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformInvoiceController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformInvoiceController';
import {
    DataTable,
    Field,
    FieldGroup,
    Section,
    SidePanel,
    Switch,
} from '@/components/page';
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

function EntityEditor({
    entity,
    onClose,
}: {
    entity: Entity | null;
    onClose: () => void;
}) {
    const form = useForm<Omit<Entity, 'id'>>(entity ?? blank);
    const errors = form.errors as Record<string, string | undefined>;
    const text = (
        key: keyof Omit<Entity, 'id'>,
        label: string,
        type = 'text',
        hint?: string,
    ) => (
        <Field label={label} hint={hint} error={errors[key]}>
            <Input
                type={type}
                value={(form.data[key] as string | null) ?? ''}
                onChange={(e) => form.setData(key, e.target.value as never)}
            />
        </Field>
    );
    const area = (
        key: 'address' | 'payment_instructions' | 'footer',
        label: string,
        hint?: string,
    ) => (
        <Field label={label} hint={hint} error={errors[key]}>
            <textarea
                className="min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                value={(form.data[key] as string | null) ?? ''}
                onChange={(e) => form.setData(key, e.target.value)}
            />
        </Field>
    );

    const save = () => {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (entity) {
            form.put(
                PlatformInvoiceController.updateEntity.url(entity.id),
                options,
            );
        } else {
            form.post(PlatformInvoiceController.storeEntity.url(), options);
        }
    };

    return (
        <SidePanel
            open
            onClose={onClose}
            title={entity ? entity.name : t('New issuing company')}
            description={t(
                'Its name, numbers and taxes appear on the invoices it issues.',
            )}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button disabled={form.processing} onClick={save}>
                        {entity ? t('Save') : t('Add issuing company')}
                    </Button>
                </>
            }
        >
            <div className="grid gap-6">
                <FieldGroup title={t('Company')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {text(
                            'name',
                            t('Name'),
                            'text',
                            t('Short name used in the platform'),
                        )}
                        {text('legal_name', t('Legal name'))}
                        {text('country_code', t('Country (2 letters)'))}
                        {text('registration', t('Registration (RCCM)'))}
                        {text('tax_id', t('Tax ID (NCC)'))}
                        {text('email', t('Email'), 'email')}
                        {text('phone', t('Phone'))}
                    </div>
                    {area('address', t('Address'))}
                </FieldGroup>
                <FieldGroup title={t('Invoices')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {text(
                            'invoice_prefix',
                            t('Invoice prefix'),
                            'text',
                            t('e.g. CNH → CNH-2026-0001'),
                        )}
                        <Field label={t('Payment terms (days)')}>
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
                        </Field>
                    </div>
                    {area(
                        'payment_instructions',
                        t('How to pay (bank, mobile money…)'),
                    )}
                    {area('footer', t('Footer (legal mentions)'))}
                </FieldGroup>
                <FieldGroup title={t('Taxes')}>
                    <div className="grid gap-2">
                        {form.data.tax_rules.map((r, i) => (
                            <div
                                key={i}
                                className="grid grid-cols-[1fr_7rem_auto] items-center gap-2"
                            >
                                <Input
                                    aria-label={t('Tax name')}
                                    value={r.name}
                                    onChange={(e) =>
                                        form.setData(
                                            'tax_rules',
                                            form.data.tax_rules.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          name: e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                />
                                <div className="flex items-center gap-1">
                                    <Input
                                        aria-label={t('Rate (%)')}
                                        type="number"
                                        step="any"
                                        value={r.rate}
                                        onChange={(e) =>
                                            form.setData(
                                                'tax_rules',
                                                form.data.tax_rules.map(
                                                    (x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  rate: Number(
                                                                      e.target
                                                                          .value,
                                                                  ),
                                                              }
                                                            : x,
                                                ),
                                            )
                                        }
                                    />
                                    <span className="text-muted-foreground">
                                        %
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    className="px-2 text-xs text-muted-foreground hover:text-destructive"
                                    aria-label={t('Remove')}
                                    onClick={() =>
                                        form.setData(
                                            'tax_rules',
                                            form.data.tax_rules.filter(
                                                (_, j) => j !== i,
                                            ),
                                        )
                                    }
                                >
                                    ✕
                                </button>
                            </div>
                        ))}
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    form.setData('tax_rules', [
                                        ...form.data.tax_rules,
                                        { name: '', rate: 0 },
                                    ])
                                }
                            >
                                {t('Add a tax')}
                            </Button>
                        </div>
                    </div>
                    <Switch
                        label={t('Plan prices include tax')}
                        checked={form.data.prices_include_tax}
                        onChange={(v) => form.setData('prices_include_tax', v)}
                    />
                </FieldGroup>
                <FieldGroup title={t('Availability')}>
                    <Switch
                        label={t('Default issuing company')}
                        checked={form.data.is_default}
                        onChange={(v) => form.setData('is_default', v)}
                    />
                    <Switch
                        label={t('Active')}
                        checked={form.data.active}
                        onChange={(v) => form.setData('active', v)}
                    />
                </FieldGroup>
            </div>
        </SidePanel>
    );
}

export function EntitiesTab({ entities }: { entities: Entity[] }) {
    const [editing, setEditing] = useState<Entity | 'new' | null>(null);

    return (
        <>
            <Section
                flush
                title={t('Issuing companies')}
                description={t(
                    'The companies whose name, numbering and taxes appear on invoices.',
                )}
                actions={
                    <Button size="sm" onClick={() => setEditing('new')}>
                        {t('Add issuing company')}
                    </Button>
                }
            >
                <DataTable
                    rows={entities}
                    rowKey={(e) => e.id}
                    empty={t('No issuing company yet: invoices need one.')}
                    onRowClick={(e) => setEditing(e)}
                    columns={[
                        {
                            key: 'name',
                            header: t('Company'),
                            cell: (e) => (
                                <div>
                                    <div className="flex items-center gap-2 font-medium">
                                        {e.name}
                                        {e.is_default && (
                                            <Badge variant="secondary">
                                                {t('Default')}
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {e.legal_name}
                                    </div>
                                </div>
                            ),
                        },
                        {
                            key: 'country',
                            header: t('Country'),
                            cell: (e) => e.country_code,
                        },
                        {
                            key: 'prefix',
                            header: t('Invoice prefix'),
                            cell: (e) => (
                                <span className="font-mono">
                                    {e.invoice_prefix}
                                </span>
                            ),
                        },
                        {
                            key: 'tax',
                            header: t('Taxes'),
                            cell: (e) =>
                                e.tax_rules
                                    .map((r) => `${r.name} ${r.rate}%`)
                                    .join(', ') || '—',
                        },
                        {
                            key: 'status',
                            header: t('Status'),
                            cell: (e) =>
                                e.active ? (
                                    <Badge>{t('Active')}</Badge>
                                ) : (
                                    <Badge variant="outline">{t('Off')}</Badge>
                                ),
                        },
                    ]}
                />
            </Section>
            {editing !== null && (
                <EntityEditor
                    key={editing === 'new' ? 'new' : editing.id}
                    entity={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
