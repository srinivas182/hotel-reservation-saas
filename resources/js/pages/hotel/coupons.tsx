import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CouponController from '@/actions/App/Domains/Rates/Http/Controllers/CouponController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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

/** Currencies without decimals (amounts are typed and stored in whole units). */
const NO_DECIMALS = [
    'XOF',
    'XAF',
    'GNF',
    'RWF',
    'UGX',
    'BIF',
    'KMF',
    'MGA',
    'JPY',
];
const factor = (currency: string) => (NO_DECIMALS.includes(currency) ? 1 : 100);
const money = (minor: number, currency: string) =>
    `${(minor / factor(currency)).toLocaleString()} ${currency}`;

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
            className="grid gap-4 rounded-xl border bg-card p-4 text-sm sm:grid-cols-3"
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
            <label className="grid content-start gap-1.5 text-xs font-medium">
                {t('Code')}
                <Input
                    value={form.data.code}
                    placeholder="WELCOME10"
                    className="font-mono uppercase"
                    onChange={(e) =>
                        form.setData('code', e.target.value.toUpperCase())
                    }
                />
                <InputError message={errors.code} />
            </label>
            <label className="grid content-start gap-1.5 text-xs font-medium">
                {t('Discount')}
                <div className="flex gap-2">
                    <select
                        className="h-9 w-28"
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
                <InputError message={errors.value} />
            </label>
            <label className="grid content-start gap-1.5 text-xs font-medium">
                {t('Note (only staff see it)')}
                <Input
                    value={form.data.note}
                    placeholder={t('e.g. Orange partners, returning guests')}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
            </label>
            <label className="grid content-start gap-1.5 text-xs font-medium">
                {t('Valid from')}
                <Input
                    type="date"
                    value={form.data.valid_from}
                    onChange={(e) => form.setData('valid_from', e.target.value)}
                />
            </label>
            <label className="grid content-start gap-1.5 text-xs font-medium">
                {t('Valid until')}
                <Input
                    type="date"
                    value={form.data.valid_until}
                    onChange={(e) =>
                        form.setData('valid_until', e.target.value)
                    }
                />
                <InputError message={errors.valid_until} />
            </label>
            <div className="grid grid-cols-2 gap-3">
                <label className="grid content-start gap-1.5 text-xs font-medium">
                    {t('Maximum uses')}
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
                </label>
                <label className="grid content-start gap-1.5 text-xs font-medium">
                    {t('Minimum nights')}
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
                </label>
            </div>
            <div className="flex flex-wrap items-center gap-3 sm:col-span-3">
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.active}
                        onChange={(e) =>
                            form.setData('active', e.target.checked)
                        }
                    />
                    {t('Active')}
                </label>
                <Button size="sm" disabled={form.processing}>
                    {coupon ? t('Save') : t('Create the code')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onDone}
                >
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}

export default function Coupons({ coupons, currency, canManage }: Props) {
    const [editing, setEditing] = useState<number | 'new' | null>(null);

    return (
        <>
            <Head title={t('Discount codes')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('Discount codes')}
                        description={t(
                            'Codes you give out (campaigns, companies, returning guests). Guests give the code on WhatsApp; the assistant applies it and reception sees it on the request.',
                        )}
                    />
                    {canManage && editing === null && (
                        <Button onClick={() => setEditing('new')}>
                            {t('New code')}
                        </Button>
                    )}
                </div>

                {editing === 'new' && (
                    <CouponForm
                        currency={currency}
                        onDone={() => setEditing(null)}
                    />
                )}

                {coupons.length === 0 && editing === null && (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {t('No discount codes yet.')}
                    </p>
                )}

                <ul className="flex flex-col gap-3">
                    {coupons.map((c) =>
                        editing === c.id ? (
                            <li key={c.id}>
                                <CouponForm
                                    coupon={c}
                                    currency={currency}
                                    onDone={() => setEditing(null)}
                                />
                            </li>
                        ) : (
                            <li
                                key={c.id}
                                className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border bg-card px-4 py-3 text-sm"
                            >
                                <span className="font-mono font-semibold">
                                    {c.code}
                                </span>
                                <Badge variant="outline">
                                    {c.kind === 'percent'
                                        ? `−${c.value}%`
                                        : `−${money(c.value, currency)}`}
                                </Badge>
                                {!c.active && (
                                    <Badge variant="secondary">
                                        {t('Inactive')}
                                    </Badge>
                                )}
                                <span className="min-w-0 flex-1 text-muted-foreground">
                                    {[
                                        c.note,
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
                                        c.max_uses
                                            ? t(':used of :max uses', {
                                                  used: c.used_count,
                                                  max: c.max_uses,
                                              })
                                            : t(':used uses', {
                                                  used: c.used_count,
                                              }),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {t(':count bookings · :amount off', {
                                        count: c.bookings,
                                        amount: money(
                                            c.discount_minor,
                                            currency,
                                        ),
                                    })}
                                </span>
                                {canManage && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setEditing(c.id)}
                                    >
                                        {t('Edit')}
                                    </Button>
                                )}
                            </li>
                        ),
                    )}
                </ul>
            </div>
        </>
    );
}

Coupons.layout = {
    breadcrumbs: [{ title: 'Discount codes', href: CouponController.index() }],
};
