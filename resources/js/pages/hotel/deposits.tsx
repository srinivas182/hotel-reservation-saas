import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, KeyRound, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { DepositStatus } from '@/components/bookings/deposit-panel';
import type { DepositRow } from '@/components/bookings/deposit-panel';
import Heading from '@/components/heading';
import {
    DataTable,
    PageBody,
    PageHeader,
    Section,
    StatTile,
    Tabs,
    useTabs,
} from '@/components/page';
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
        prepay_discount_percent: number;
        pay_at_hotel_hold_until: string | null;
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
    webhook_secret: 'Webhook signing secret (whsec_…)',
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
            className="flex flex-col gap-3 rounded-xl border bg-card p-5 text-sm"
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
                    {account.driver === 'stripe' && (
                        <span className="block">
                            {t(
                                'Stripe: Developers → Webhooks → Add endpoint with this address and the events checkout.session.completed and checkout.session.expired; then paste its signing secret above.',
                            )}
                        </span>
                    )}
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
        prepay_discount_percent: String(settings.prepay_discount_percent ?? 0),
        pay_at_hotel_hold_until: settings.pay_at_hotel_hold_until ?? '',
    });

    return (
        <form
            className="flex flex-col gap-3 rounded-xl border bg-card p-5 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => ({
                    deposit_online: data.deposit_online,
                    deposit_allow_full: data.deposit_allow_full,
                    deposit_refund_days:
                        data.refund === 'never'
                            ? null
                            : Number(data.deposit_refund_days) || 0,
                    prepay_discount_percent:
                        Number(data.prepay_discount_percent) || 0,
                    pay_at_hotel_hold_until:
                        data.pay_at_hotel_hold_until === ''
                            ? null
                            : data.pay_at_hotel_hold_until,
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
            <fieldset className="grid gap-3 border-t pt-3 sm:grid-cols-2">
                <legend className="mb-1 font-medium">
                    {t('Pay now or pay at the hotel')}
                </legend>
                <label className="grid gap-1.5 text-xs font-medium">
                    {t('Discount for paying the whole stay now (%)')}
                    <Input
                        inputMode="numeric"
                        className="w-24"
                        value={form.data.prepay_discount_percent}
                        onChange={(e) =>
                            form.setData(
                                'prepay_discount_percent',
                                e.target.value.replace(/\D/g, '').slice(0, 2),
                            )
                        }
                    />
                    <span className="font-normal text-muted-foreground">
                        {t(
                            '0 = no pay-now offer. With a discount, the confirmation offers both: pay now online at the lower price, or pay the full price at the hotel. Needs online payments (above).',
                        )}
                    </span>
                    <InputError message={form.errors.prepay_discount_percent} />
                </label>
                <label className="grid gap-1.5 text-xs font-medium">
                    {t('Pay-at-the-hotel rooms kept until (arrival day)')}
                    <Input
                        type="time"
                        className="w-32"
                        value={form.data.pay_at_hotel_hold_until}
                        onChange={(e) =>
                            form.setData(
                                'pay_at_hotel_hold_until',
                                e.target.value,
                            )
                        }
                    />
                    <span className="font-normal text-muted-foreground">
                        {t(
                            'Told to the guest in the confirmation. Leave empty to say nothing.',
                        )}
                    </span>
                    <InputError message={form.errors.pay_at_hotel_hold_until} />
                </label>
            </fieldset>
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
    const tabs = [
        { key: 'payments', label: t('Payments') },
        ...(props.canManage
            ? [{ key: 'settings', label: t('Account and rules') }]
            : []),
    ];
    const [tab, setTab] = useTabs(tabs);

    return (
        <>
            <Head title={t('Deposits')} />
            <PageBody>
                <PageHeader
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
                    actions={
                        tab === 'payments' && (
                            <>
                                <Input
                                    type="month"
                                    aria-label={t('Month')}
                                    className="w-44"
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
                                <Button asChild variant="outline">
                                    <a
                                        href={`/hotel/deposits/export?month=${month}`}
                                    >
                                        <Download aria-hidden />
                                        {t('Export for the accountant')}
                                    </a>
                                </Button>
                            </>
                        )
                    }
                />
                {tabs.length > 1 && (
                    <Tabs tabs={tabs} active={tab} onSelect={setTab} />
                )}

                {tab === 'payments' && (
                    <>
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <StatTile
                                label={t('Deposits received')}
                                value={props.totals.count}
                                hint={props.totals.total ?? undefined}
                            />
                            <StatTile
                                label={t('Waiting for payment')}
                                value={props.open.length}
                            />
                            <StatTile
                                label={t('Refunds to make')}
                                value={
                                    <span
                                        className={
                                            props.refunds.length > 0
                                                ? 'text-destructive'
                                                : ''
                                        }
                                    >
                                        {props.refunds.length}
                                    </span>
                                }
                            />
                            <StatTile
                                label={t('By method')}
                                value={
                                    <span className="text-base">
                                        {props.totals.by_method.length === 0
                                            ? '—'
                                            : props.totals.by_method
                                                  .map(
                                                      (m) =>
                                                          `${m.method} ${m.count}`,
                                                  )
                                                  .join(' · ')}
                                    </span>
                                }
                            />
                        </div>

                        {props.refunds.length > 0 && (
                            <section
                                aria-labelledby="refunds-heading"
                                className="overflow-hidden rounded-xl border border-destructive/40 bg-card"
                            >
                                <h2
                                    id="refunds-heading"
                                    className="border-b border-destructive/30 bg-destructive/5 px-5 py-3 font-medium text-destructive"
                                >
                                    {t('Refunds to make')}
                                </h2>
                                <ul className="divide-y px-5">
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

                        <Section flush title={t('Waiting for payment')}>
                            <DataTable
                                rows={props.open}
                                rowKey={(r) => r.id}
                                empty={t('No deposit is waiting.')}
                                onRowClick={(r) =>
                                    router.visit(
                                        `/hotel/bookings/${r.booking_id}`,
                                    )
                                }
                                columns={[
                                    {
                                        key: 'guest',
                                        header: t('Guest'),
                                        cell: (r) => (
                                            <div>
                                                <div className="font-medium">
                                                    {r.guest}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {r.reference}
                                                </div>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'deposit',
                                        header: t('Amount'),
                                        align: 'right',
                                        cell: (r) => r.deposit,
                                    },
                                    {
                                        key: 'held',
                                        header: t('Held until'),
                                        cell: (r) => (
                                            <span className="text-muted-foreground">
                                                {r.expires_at ?? '—'}
                                                {r.reminded_at &&
                                                    ' · ' + t('reminded')}
                                            </span>
                                        ),
                                    },
                                ]}
                            />
                        </Section>

                        <Section flush title={t('Paid this month')}>
                            <DataTable
                                rows={props.paid}
                                rowKey={(r) => r.id}
                                empty={t('No deposit paid this month.')}
                                columns={[
                                    {
                                        key: 'guest',
                                        header: t('Guest'),
                                        cell: (r) => (
                                            <div>
                                                <Link
                                                    href={`/hotel/bookings/${r.booking_id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {r.guest}
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    {r.reference} · {r.paid_at}
                                                </div>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'amount',
                                        header: t('Amount'),
                                        align: 'right',
                                        cell: (r) => r.paid,
                                    },
                                    {
                                        key: 'method',
                                        header: t('Method'),
                                        cell: (r) => (
                                            <div>
                                                {r.method}
                                                {r.via === 'manual' && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {t('by :name', {
                                                            name:
                                                                r.confirmed_by ??
                                                                '—',
                                                        })}
                                                    </div>
                                                )}
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'receipt',
                                        header: t('Receipt'),
                                        cell: (r) => (
                                            <span className="font-mono text-xs">
                                                {r.receipt}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'status',
                                        header: t('Status'),
                                        cell: (r) => <DepositStatus row={r} />,
                                    },
                                ]}
                            />
                        </Section>
                    </>
                )}

                {tab === 'settings' && props.canManage && (
                    <>
                        <AccountForm
                            account={props.account}
                            drivers={props.drivers}
                        />
                        <RulesForm settings={props.settings} />
                    </>
                )}
            </PageBody>
        </>
    );
}
