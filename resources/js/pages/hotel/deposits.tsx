import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, KeyRound, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { DepositStatus } from '@/components/bookings/deposit-panel';
import type { DepositRow } from '@/components/bookings/deposit-panel';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Driver = { value: string; label: string; fields: string[] };

type Props = {
    month: string;
    online: boolean;
    canManage: boolean;
    canRefund: boolean;
    account: {
        driver: string;
        mode: 'test' | 'live';
        active: boolean;
        masked: Record<string, string>;
        checked_at: string | null;
        last_error: string | null;
        notify_url: string;
    } | null;
    drivers: Driver[];
    settings: {
        deposit_required: boolean;
        deposit_percent: number;
        deposit_hours: number;
        deposit_online: boolean;
        deposit_allow_full: boolean;
        deposit_refund_days: number | null;
    };
    totals: {
        count: number;
        total: string | null;
        refunds_due: number;
        by_method: { method: string; count: number; total: string }[];
    };
    refunds: DepositRow[];
    open: DepositRow[];
    paid: DepositRow[];
};

const FIELD_LABELS: Record<string, string> = {
    apikey: 'API key',
    site_id: 'Site ID',
    secret_key: 'Secret key',
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function RefundRow({
    row,
    canRefund,
}: {
    row: DepositRow;
    canRefund: boolean;
}) {
    const form = useForm({ note: '' });

    return (
        <li className="flex flex-col gap-2 py-3 text-sm sm:flex-row sm:items-end">
            <div className="flex-1">
                <Link
                    href={`/hotel/bookings/${row.booking_id}`}
                    className="font-medium underline-offset-4 hover:underline"
                >
                    {row.guest} · {row.reference}
                </Link>
                <span className="block text-muted-foreground">
                    {t(':amount paid by :method on :at', {
                        amount: row.refund_due ?? '',
                        method: row.method ?? '',
                        at: row.paid_at ?? '',
                    })}
                </span>
            </div>
            {canRefund && (
                <form
                    className="flex flex-1 flex-col gap-1"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/hotel/deposits/${row.id}/refunded`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <div className="flex gap-2">
                        <Input
                            aria-label={t('How it was refunded')}
                            placeholder={t('Sent back by Wave on 12/11')}
                            value={form.data.note}
                            maxLength={200}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                        <Button
                            type="submit"
                            size="sm"
                            disabled={form.processing}
                        >
                            {t('Refunded')}
                        </Button>
                    </div>
                    <InputError message={form.errors.note} />
                </form>
            )}
        </li>
    );
}

function AccountForm({
    account,
    drivers,
}: {
    account: Props['account'];
    drivers: Driver[];
}) {
    const form = useForm<{
        driver: string;
        mode: 'test' | 'live';
        active: boolean;
        credentials: Record<string, string>;
    }>({
        driver: account?.driver ?? drivers[0]?.value ?? 'cinetpay',
        mode: account?.mode ?? 'live',
        active: account?.active ?? false,
        credentials: {},
    });
    const errors = form.errors as Record<string, string | undefined>;
    const driver = drivers.find((d) => d.value === form.data.driver);

    return (
        <form
            className="flex flex-col gap-3 rounded-xl border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.put('/hotel/deposits/account', {
                    preserveScroll: true,
                    onSuccess: () => form.setData('credentials', {}),
                });
            }}
        >
            <Heading
                variant="small"
                title={t('Your mobile-money account')}
                description={t(
                    'Guests pay your hotel directly through your own provider account: the money never passes through us. Keys are stored encrypted and never shown again.',
                )}
            />
            <div className="flex flex-wrap gap-3">
                <label className="grid gap-1">
                    <span className="font-medium">{t('Provider')}</span>
                    <select
                        className={selectClass}
                        value={form.data.driver}
                        onChange={(e) => form.setData('driver', e.target.value)}
                    >
                        {drivers.map((d) => (
                            <option key={d.value} value={d.value}>
                                {d.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1">
                    <span className="font-medium">{t('Mode')}</span>
                    <select
                        className={selectClass}
                        value={form.data.mode}
                        onChange={(e) =>
                            form.setData(
                                'mode',
                                e.target.value as 'test' | 'live',
                            )
                        }
                    >
                        <option value="live">{t('Live')}</option>
                        <option value="test">{t('Test')}</option>
                    </select>
                </label>
            </div>
            {driver?.fields.map((field) => (
                <label key={field} className="grid gap-1">
                    <span className="font-medium">
                        {FIELD_LABELS[field] ?? field}
                    </span>
                    <Input
                        type="password"
                        autoComplete="off"
                        placeholder={
                            account?.driver === form.data.driver &&
                            account.masked[field]
                                ? account.masked[field] +
                                  ' · ' +
                                  t('leave empty to keep')
                                : ''
                        }
                        value={form.data.credentials[field] ?? ''}
                        onChange={(e) =>
                            form.setData('credentials', {
                                ...form.data.credentials,
                                [field]: e.target.value,
                            })
                        }
                    />
                </label>
            ))}
            <InputError message={errors.credentials ?? errors.driver} />
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.active}
                    onChange={(e) => form.setData('active', e.target.checked)}
                />
                {t('Take payments with this account')}
            </label>
            {account && (
                <p className="text-xs text-muted-foreground">
                    {t('Notification address to give your provider:')}{' '}
                    <code className="break-all">{account.notify_url}</code>
                    {account.checked_at && (
                        <span className="block">
                            {account.last_error
                                ? t('Last check on :at: refused (:error).', {
                                      at: account.checked_at,
                                      error: account.last_error,
                                  })
                                : t('Last check on :at: keys accepted.', {
                                      at: account.checked_at,
                                  })}
                        </span>
                    )}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    <KeyRound aria-hidden />
                    {t('Save')}
                </Button>
                {account && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                '/hotel/deposits/account/check',
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <ShieldCheck aria-hidden />
                        {t('Test the keys')}
                    </Button>
                )}
            </div>
        </form>
    );
}

function RulesForm({ settings }: { settings: Props['settings'] }) {
    const form = useForm({
        deposit_online: settings.deposit_online,
        deposit_allow_full: settings.deposit_allow_full,
        refund: settings.deposit_refund_days === null ? 'never' : 'days',
        deposit_refund_days: String(settings.deposit_refund_days ?? 2),
    });

    return (
        <form
            className="flex flex-col gap-3 rounded-xl border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => ({
                    deposit_online: data.deposit_online,
                    deposit_allow_full: data.deposit_allow_full,
                    deposit_refund_days:
                        data.refund === 'never'
                            ? null
                            : Number(data.deposit_refund_days) || 0,
                }));
                form.put('/hotel/deposits/settings', { preserveScroll: true });
            }}
        >
            <Heading
                variant="small"
                title={t('Deposit rules')}
                description={
                    settings.deposit_required
                        ? t(
                              'Deposit of :percent%, rooms held :hours hours. Change these in AI assistant settings.',
                              {
                                  percent: settings.deposit_percent,
                                  hours: settings.deposit_hours,
                              },
                          )
                        : t(
                              'No deposit is asked for at the moment. Turn it on in AI assistant settings.',
                          )
                }
            />
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.deposit_online}
                    onChange={(e) =>
                        form.setData('deposit_online', e.target.checked)
                    }
                />
                {t('Send a payment link with the deposit request')}
            </label>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.deposit_allow_full}
                    onChange={(e) =>
                        form.setData('deposit_allow_full', e.target.checked)
                    }
                />
                {t('Let guests pay the whole stay instead')}
            </label>
            <fieldset className="flex flex-wrap items-center gap-2">
                <legend className="mb-1 font-medium">
                    {t('When a booking is cancelled')}
                </legend>
                <select
                    className={selectClass}
                    value={form.data.refund}
                    aria-label={t('Refund policy')}
                    onChange={(e) => form.setData('refund', e.target.value)}
                >
                    <option value="days">
                        {t('Refund the deposit if cancelled at least')}
                    </option>
                    <option value="never">
                        {t('Never refund the deposit')}
                    </option>
                </select>
                {form.data.refund === 'days' && (
                    <>
                        <Input
                            className="w-20"
                            inputMode="numeric"
                            aria-label={t('Days before arrival')}
                            value={form.data.deposit_refund_days}
                            onChange={(e) =>
                                form.setData(
                                    'deposit_refund_days',
                                    e.target.value.replace(/\D/g, ''),
                                )
                            }
                        />
                        <span>{t('days before arrival')}</span>
                    </>
                )}
            </fieldset>
            <InputError message={form.errors.deposit_refund_days} />
            <Button
                type="submit"
                size="sm"
                className="w-fit"
                disabled={form.processing}
            >
                {t('Save')}
            </Button>
        </form>
    );
}

export default function Deposits(props: Props) {
    const [month, setMonth] = useState(props.month);

    return (
        <>
            <Head title={t('Deposits')} />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title={t('Deposits')}
                    description={
                        props.online
                            ? t(
                                  'Guests pay their deposit by mobile money from a link; bookings confirm themselves.',
                              )
                            : t(
                                  'Online payment is off: guests follow your instructions and you confirm each deposit on its booking.',
                              )
                    }
                />

                <section
                    className="flex flex-wrap items-end gap-3"
                    aria-label={t('Month')}
                >
                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">{t('Month')}</span>
                        <Input
                            type="month"
                            value={month}
                            onChange={(e) => {
                                setMonth(e.target.value);
                                router.get(
                                    '/hotel/deposits',
                                    { month: e.target.value },
                                    {
                                        preserveState: true,
                                        preserveScroll: true,
                                    },
                                );
                            }}
                        />
                    </label>
                    <Button asChild variant="outline" size="sm">
                        <a href={`/hotel/deposits/export?month=${month}`}>
                            <Download aria-hidden />
                            {t('Export for the accountant')}
                        </a>
                    </Button>
                </section>

                <section className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border p-4">
                        <p className="text-sm text-muted-foreground">
                            {t('Deposits received')}
                        </p>
                        <p className="text-2xl font-semibold">
                            {props.totals.count}
                        </p>
                        <p className="text-sm">{props.totals.total ?? '—'}</p>
                    </div>
                    <div className="rounded-xl border p-4 sm:col-span-2">
                        <p className="text-sm text-muted-foreground">
                            {t('By method')}
                        </p>
                        {props.totals.by_method.length === 0 ? (
                            <p className="text-sm">—</p>
                        ) : (
                            <ul className="mt-1 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                {props.totals.by_method.map((m) => (
                                    <li key={m.method}>
                                        <span className="font-medium">
                                            {m.method}
                                        </span>{' '}
                                        {m.count} · {m.total}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>

                {props.refunds.length > 0 && (
                    <section aria-labelledby="refunds-heading">
                        <h2
                            id="refunds-heading"
                            className="font-medium text-destructive"
                        >
                            {t('Refunds to make')}
                        </h2>
                        <ul className="divide-y">
                            {props.refunds.map((row) => (
                                <RefundRow
                                    key={row.id}
                                    row={row}
                                    canRefund={props.canRefund}
                                />
                            ))}
                        </ul>
                    </section>
                )}

                <section aria-labelledby="open-heading">
                    <h2 id="open-heading" className="font-medium">
                        {t('Waiting for payment')}
                    </h2>
                    {props.open.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('No deposit is waiting.')}
                        </p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {props.open.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex flex-wrap items-center gap-x-3 py-2"
                                >
                                    <Link
                                        href={`/hotel/bookings/${row.booking_id}`}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.guest} · {row.reference}
                                    </Link>
                                    <span>{row.deposit}</span>
                                    <span className="text-muted-foreground">
                                        {t('held until :at', {
                                            at: row.expires_at ?? '—',
                                        })}
                                        {row.reminded_at &&
                                            ' · ' + t('reminded')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-labelledby="paid-heading">
                    <h2 id="paid-heading" className="font-medium">
                        {t('Paid this month')}
                    </h2>
                    {props.paid.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('No deposit paid this month.')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-muted-foreground">
                                        <th className="py-2 pr-3 font-normal">
                                            {t('Paid at')}
                                        </th>
                                        <th className="py-2 pr-3 font-normal">
                                            {t('Guest')}
                                        </th>
                                        <th className="py-2 pr-3 font-normal">
                                            {t('Amount')}
                                        </th>
                                        <th className="py-2 pr-3 font-normal">
                                            {t('Method')}
                                        </th>
                                        <th className="py-2 pr-3 font-normal">
                                            {t('Receipt')}
                                        </th>
                                        <th className="py-2 font-normal">
                                            {t('Status')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {props.paid.map((row) => (
                                        <tr key={row.id}>
                                            <td className="py-2 pr-3 whitespace-nowrap">
                                                {row.paid_at}
                                            </td>
                                            <td className="py-2 pr-3">
                                                <Link
                                                    href={`/hotel/bookings/${row.booking_id}`}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {row.guest} ·{' '}
                                                    {row.reference}
                                                </Link>
                                            </td>
                                            <td className="py-2 pr-3 whitespace-nowrap">
                                                {row.paid}
                                            </td>
                                            <td className="py-2 pr-3">
                                                {row.method}
                                                {row.via === 'manual' && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {t('by :name', {
                                                            name:
                                                                row.confirmed_by ??
                                                                '—',
                                                        })}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2 pr-3 font-mono text-xs">
                                                {row.receipt}
                                            </td>
                                            <td className="py-2">
                                                <DepositStatus row={row} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {props.canManage && (
                    <section className="flex flex-col gap-4">
                        <AccountForm
                            account={props.account}
                            drivers={props.drivers}
                        />
                        <RulesForm settings={props.settings} />
                    </section>
                )}
            </div>
        </>
    );
}
