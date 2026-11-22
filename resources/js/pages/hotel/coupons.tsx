import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import CouponController from '@/actions/App/Domains/Rates/Http/Controllers/CouponController';
import {
    DataTable,
    Field,
    FieldGroup,
    formatMoney,
    minorFactor,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    StatTile,
    Switch,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Coupon = {
    id: number;
    code: string;
    note: string | null;
    kind: 'percent' | 'fixed';
    value: number;
    valid_from: string | null;
    valid_until: string | null;
    max_uses: number | null;
    used_count: number;
    min_nights: number | null;
    active: boolean;
    bookings: number;
    discount_minor: number;
};

type Props = { coupons: Coupon[]; currency: string; canManage: boolean };

const factor = minorFactor;
const money = (minor: number, currency: string) => formatMoney(minor, currency);

function CouponForm({
    coupon,
    currency,
    onDone,
}: {
    coupon?: Coupon;
    currency: string;
    onDone: () => void;
}) {
    const form = useForm({
        code: coupon?.code ?? '',
        note: coupon?.note ?? '',
        kind: coupon?.kind ?? 'percent',
        value: coupon
            ? String(
                  coupon.kind === 'fixed'
                      ? coupon.value / factor(currency)
                      : coupon.value,
              )
            : '10',
        valid_from: coupon?.valid_from ?? '',
        valid_until: coupon?.valid_until ?? '',
        max_uses: coupon?.max_uses ? String(coupon.max_uses) : '',
        min_nights: coupon?.min_nights ? String(coupon.min_nights) : '',
        active: coupon?.active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            className="grid gap-6"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    value:
                        d.kind === 'fixed'
                            ? Math.round(Number(d.value) * factor(currency))
                            : Number(d.value),
                    valid_from: d.valid_from || null,
                    valid_until: d.valid_until || null,
                    max_uses: d.max_uses ? Number(d.max_uses) : null,
                    min_nights: d.min_nights ? Number(d.min_nights) : null,
                    note: d.note || null,
                }));
                const options = { preserveScroll: true, onSuccess: onDone };
                if (coupon) {
                    form.put(CouponController.update.url(coupon.id), options);
                } else {
                    form.post(CouponController.store.url(), options);
                }
            }}
        >
            <FieldGroup title={t('Code')}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label={t('Code')}
                        hint={t('What the guest types on WhatsApp.')}
                        error={errors.code}
                    >
                        <Input
                            value={form.data.code}
                            placeholder="WELCOME10"
                            className="font-mono uppercase"
                            onChange={(e) =>
                                form.setData(
                                    'code',
                                    e.target.value.toUpperCase(),
                                )
                            }
                        />
                    </Field>
                    <Field label={t('Discount')} error={errors.value}>
                        <div className="flex gap-2">
                            <select
                                className="h-9 w-24"
                                value={form.data.kind}
                                aria-label={t('Kind of discount')}
                                onChange={(e) =>
                                    form.setData(
                                        'kind',
                                        e.target.value as 'percent' | 'fixed',
                                    )
                                }
                            >
                                <option value="percent">%</option>
                                <option value="fixed">{currency}</option>
                            </select>
                            <Input
                                inputMode="decimal"
                                aria-label={t('Value')}
                                value={form.data.value}
                                onChange={(e) =>
                                    form.setData(
                                        'value',
                                        e.target.value.replace(/[^\d.]/g, ''),
                                    )
                                }
                            />
                        </div>
                    </Field>
                </div>
                <Field label={t('Note (only staff see it)')}>
                    <Input
                        value={form.data.note}
                        placeholder={t(
                            'e.g. Orange partners, returning guests',
                        )}
                        onChange={(e) => form.setData('note', e.target.value)}
                    />
                </Field>
            </FieldGroup>
            <FieldGroup title={t('Limits')} description={t('All optional.')}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('Valid from')}>
                        <Input
                            type="date"
                            value={form.data.valid_from}
                            onChange={(e) =>
                                form.setData('valid_from', e.target.value)
                            }
                        />
                    </Field>
                    <Field label={t('Valid until')} error={errors.valid_until}>
                        <Input
                            type="date"
                            value={form.data.valid_until}
                            onChange={(e) =>
                                form.setData('valid_until', e.target.value)
                            }
                        />
                    </Field>
                    <Field label={t('Maximum uses')}>
                        <Input
                            inputMode="numeric"
                            placeholder={t('No limit')}
                            value={form.data.max_uses}
                            onChange={(e) =>
                                form.setData(
                                    'max_uses',
                                    e.target.value.replace(/\D/g, ''),
                                )
                            }
                        />
                    </Field>
                    <Field label={t('Minimum nights')}>
                        <Input
                            inputMode="numeric"
                            placeholder="1"
                            value={form.data.min_nights}
                            onChange={(e) =>
                                form.setData(
                                    'min_nights',
                                    e.target.value.replace(/\D/g, ''),
                                )
                            }
                        />
                    </Field>
                </div>
                <Switch
                    checked={form.data.active}
                    onChange={(v) => form.setData('active', v)}
                    label={t('Active')}
                />
            </FieldGroup>
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button disabled={form.processing}>
                    {coupon ? t('Save') : t('Create the code')}
                </Button>
            </div>
        </form>
    );
}

export default function Coupons({ coupons, currency, canManage }: Props) {
    const [editing, setEditing] = useState<Coupon | 'new' | null>(null);
    const coupon = editing === 'new' || editing === null ? undefined : editing;
    const used = coupons.reduce((n, c) => n + c.bookings, 0);
    const given = coupons.reduce((n, c) => n + c.discount_minor, 0);

    return (
        <>
            <Head title={t('Discount codes')} />
            <PageBody>
                <PageHeader
                    title={t('Discount codes')}
                    description={t(
                        'Codes you give out (campaigns, companies, returning guests). Guests give the code on WhatsApp; the assistant applies it and reception sees it on the request.',
                    )}
                    actions={
                        canManage && (
                            <Button onClick={() => setEditing('new')}>
                                <Plus /> {t('New code')}
                            </Button>
                        )
                    }
                />
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <StatTile
                        label={t('Active codes')}
                        value={coupons.filter((c) => c.active).length}
                    />
                    <StatTile label={t('Bookings with a code')} value={used} />
                    <StatTile
                        label={t('Discount given')}
                        value={money(given, currency)}
                    />
                </div>
                <Section
                    flush
                    title={t('Codes')}
                    description={
                        canManage ? t('Click a code to edit it.') : undefined
                    }
                >
                    <DataTable
                        rows={coupons}
                        rowKey={(c) => c.id}
                        empty={t('No discount codes yet.')}
                        onRowClick={canManage ? setEditing : undefined}
                        columns={[
                            {
                                key: 'code',
                                header: t('Code'),
                                cell: (c) => (
                                    <div>
                                        <div className="font-mono font-semibold">
                                            {c.code}
                                        </div>
                                        {c.note && (
                                            <div className="text-xs text-muted-foreground">
                                                {c.note}
                                            </div>
                                        )}
                                    </div>
                                ),
                            },
                            {
                                key: 'discount',
                                header: t('Discount'),
                                cell: (c) => (
                                    <Badge variant="outline">
                                        {c.kind === 'percent'
                                            ? `−${c.value}%`
                                            : `−${money(c.value, currency)}`}
                                    </Badge>
                                ),
                            },
                            {
                                key: 'limits',
                                header: t('Limits'),
                                cell: (c) => (
                                    <span className="text-xs text-muted-foreground">
                                        {[
                                            c.valid_until
                                                ? t('until :date', {
                                                      date: c.valid_until,
                                                  })
                                                : null,
                                            c.min_nights
                                                ? t(':count nights minimum', {
                                                      count: c.min_nights,
                                                  })
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || '—'}
                                    </span>
                                ),
                            },
                            {
                                key: 'uses',
                                header: t('Uses'),
                                align: 'right',
                                cell: (c) =>
                                    c.max_uses
                                        ? `${c.used_count} / ${c.max_uses}`
                                        : c.used_count,
                            },
                            {
                                key: 'result',
                                header: t('Result'),
                                align: 'right',
                                cell: (c) => (
                                    <span className="text-xs text-muted-foreground">
                                        {t(':count bookings · :amount off', {
                                            count: c.bookings,
                                            amount: money(
                                                c.discount_minor,
                                                currency,
                                            ),
                                        })}
                                    </span>
                                ),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (c) =>
                                    c.active ? (
                                        <Badge>{t('Active')}</Badge>
                                    ) : (
                                        <Badge variant="secondary">
                                            {t('Inactive')}
                                        </Badge>
                                    ),
                            },
                        ]}
                    />
                </Section>
            </PageBody>
            <SidePanel
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={coupon ? coupon.code : t('New code')}
            >
                {editing !== null && (
                    <CouponForm
                        key={coupon?.id ?? 'new'}
                        coupon={coupon}
                        currency={currency}
                        onDone={() => setEditing(null)}
                    />
                )}
            </SidePanel>
        </>
    );
}

Coupons.layout = {
    breadcrumbs: [{ title: 'Discount codes', href: CouponController.index() }],
};
