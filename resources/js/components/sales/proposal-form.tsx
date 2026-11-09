import { useForm } from '@inertiajs/react';
import ProposalController from '@/actions/App/Domains/Sales/Http/Controllers/ProposalController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

export type Plan = {
    id: number;
    name: string;
    prices: Record<string, { monthly?: number; yearly?: number }>;
};
export type RoiInputs = {
    enquiries_per_day: number;
    missed_percent: number;
    conversion_percent: number;
    average_nights: number;
    night_price: number;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const fmt = (n: number) =>
    new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(n);

/** G + H: the return on investment, computed as you type, and a proposal to send. */
export function ProposalForm({
    leadId,
    plans,
    defaults,
    hasEmail,
    rooms,
}: {
    leadId: number;
    plans: Plan[];
    defaults: RoiInputs;
    hasEmail: boolean;
    rooms: number | null;
}) {
    const first = plans[0];
    const form = useForm({
        plan_id: first?.id ?? '',
        currency: first ? (Object.keys(first.prices)[0] ?? 'XOF') : 'XOF',
        cycle: 'monthly',
        discount_percent: 0,
        roi: {
            ...defaults,
            enquiries_per_day: rooms
                ? Math.max(3, Math.round(rooms / 2))
                : defaults.enquiries_per_day,
        },
        locale: 'fr',
        note: '',
        send: hasEmail,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const plan = plans.find((p) => p.id === form.data.plan_id);
    const listPrice =
        plan?.prices[form.data.currency]?.[
            form.data.cycle as 'monthly' | 'yearly'
        ] ?? 0;
    const price = Math.round(
        (listPrice * (100 - Number(form.data.discount_percent))) / 100,
    );
    const monthly = form.data.cycle === 'yearly' ? price / 12 : price;
    const r = form.data.roi;
    const bookings =
        r.enquiries_per_day *
        (r.missed_percent / 100) *
        (r.conversion_percent / 100) *
        30;
    const revenue = bookings * r.average_nights * r.night_price;
    const payback =
        revenue > 0 ? Math.max(1, Math.ceil(monthly / (revenue / 30))) : null;
    const setRoi = (key: keyof RoiInputs, value: string) =>
        form.setData('roi', { ...form.data.roi, [key]: Number(value) });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(ProposalController.store.url(leadId), {
                    preserveScroll: true,
                });
            }}
            className="grid gap-2 rounded-lg border p-3 sm:grid-cols-2"
        >
            <p className="font-medium sm:col-span-2">
                {t('Return on investment')}
            </p>
            {(
                [
                    ['enquiries_per_day', t('WhatsApp enquiries a day')],
                    ['missed_percent', t('% missed today (nights, busy desk)')],
                    ['conversion_percent', t('% of those who would book')],
                    ['average_nights', t('Average nights')],
                    ['night_price', t('Average price per night')],
                ] as [keyof RoiInputs, string][]
            ).map(([key, label]) => (
                <label key={key} className="grid gap-0.5 text-xs">
                    {label}
                    <Input
                        type="number"
                        min={0}
                        value={form.data.roi[key]}
                        onChange={(e) => setRoi(key, e.target.value)}
                    />
                </label>
            ))}
            <div
                className="rounded-md bg-muted/50 p-2 text-sm sm:col-span-2"
                aria-live="polite"
            >
                {t(
                    ':bookings bookings and :revenue a month recovered; the plan costs :cost a month; it pays for itself in :days days.',
                    {
                        bookings: bookings.toFixed(1),
                        revenue: fmt(revenue),
                        cost: fmt(monthly),
                        days: payback ?? '—',
                    },
                )}
            </div>
            <p className="font-medium sm:col-span-2">{t('Proposal')}</p>
            <label className="grid gap-0.5 text-xs">
                {t('Plan')}
                <select
                    className={selectClass}
                    value={form.data.plan_id}
                    onChange={(e) =>
                        form.setData('plan_id', Number(e.target.value))
                    }
                >
                    {plans.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('Currency')}
                <select
                    className={selectClass}
                    value={form.data.currency}
                    onChange={(e) => form.setData('currency', e.target.value)}
                >
                    {Object.keys(plan?.prices ?? {}).map((c) => (
                        <option key={c} value={c}>
                            {c}
                        </option>
                    ))}
                </select>
                <InputError message={errors.currency} />
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('Billing')}
                <select
                    className={selectClass}
                    value={form.data.cycle}
                    onChange={(e) => form.setData('cycle', e.target.value)}
                >
                    <option value="monthly">{t('Monthly')}</option>
                    <option value="yearly">{t('Yearly')}</option>
                </select>
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('Discount (%)')}
                <Input
                    type="number"
                    min={0}
                    max={50}
                    value={form.data.discount_percent}
                    onChange={(e) =>
                        form.setData('discount_percent', Number(e.target.value))
                    }
                />
                <InputError message={errors.discount_percent} />
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('Language')}
                <select
                    className={selectClass}
                    value={form.data.locale}
                    onChange={(e) => form.setData('locale', e.target.value)}
                >
                    <option value="fr">Français</option>
                    <option value="en">English</option>
                </select>
            </label>
            <p className="self-end text-sm">
                {t('Price: :price', {
                    price: `${fmt(price)} ${form.data.currency}`,
                })}
            </p>
            <textarea
                aria-label={t('A personal word (optional)')}
                placeholder={t('A personal word (optional)')}
                className="min-h-14 rounded-md border border-input bg-transparent px-3 py-2 sm:col-span-2"
                value={form.data.note}
                onChange={(e) => form.setData('note', e.target.value)}
            />
            <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
                {hasEmail && (
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.send}
                            onChange={(e) =>
                                form.setData('send', e.target.checked)
                            }
                        />
                        {t('Email it to the prospect')}
                    </label>
                )}
                <Button size="sm" disabled={form.processing || !plan}>
                    {t('Create the proposal')}
                </Button>
            </div>
        </form>
    );
}
