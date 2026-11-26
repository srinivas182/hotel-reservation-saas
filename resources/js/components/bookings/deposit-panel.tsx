import { router, useForm } from '@inertiajs/react';
import { Check, Copy, Send, Smartphone } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

export type DepositRow = {
    id: string;
    booking_id: string;
    reference: string;
    guest: string | null;
    status: 'open' | 'paid' | 'expired' | 'cancelled';
    deposit: string;
    paid: string | null;
    method: string | null;
    via: 'online' | 'manual' | null;
    receipt: string | null;
    paid_at: string | null;
    confirmed_by: string | null;
    expires_at: string | null;
    sent_at: string | null;
    reminded_at: string | null;
    refund_status: 'due' | 'done' | 'kept' | null;
    refund_due: string | null;
    refunded_at: string | null;
    refund_note: string | null;
    /** 0.39.1: the payment can be refunded from here (Stripe). */
    online_refund?: boolean;
};

export type BookingDeposit = {
    link: (DepositRow & { url: string | null }) | null;
    online: boolean;
    can_send: boolean;
    can_confirm: boolean;
    can_refund: boolean;
    deposit: string;
    deposit_minor: number;
    methods: { value: string; label: string }[];
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export function DepositStatus({ row }: { row: DepositRow }) {
    if (row.refund_status === 'due') {
        return <Badge variant="destructive">{t('To refund')}</Badge>;
    }
    if (row.refund_status === 'done') {
        return <Badge variant="secondary">{t('Refunded')}</Badge>;
    }

    const labels: Record<DepositRow['status'], string> = {
        open: t('Waiting for payment'),
        paid: t('Paid'),
        expired: t('Expired'),
        cancelled: t('Cancelled'),
    };

    return (
        <Badge variant={row.status === 'paid' ? 'default' : 'outline'}>
            {labels[row.status]}
        </Badge>
    );
}

/**
 * The deposit of a booking (Sprint F): the link and its state, send it on
 * WhatsApp, confirm a transfer seen in the hotel's account, or a refund.
 */
export function DepositPanel({
    bookingId,
    deposit,
}: {
    bookingId: string;
    deposit: BookingDeposit;
}) {
    const [confirming, setConfirming] = useState(false);
    const [copied, setCopied] = useState(false);
    const confirm = useForm({
        method: 'mobile_money',
        amount_minor: String(deposit.deposit_minor),
    });
    const errors = confirm.errors as Record<string, string | undefined>;
    const link = deposit.link;

    return (
        <section
            className="flex flex-col gap-2 rounded-xl border p-4 text-sm"
            aria-labelledby="deposit-heading"
        >
            <div className="flex items-center justify-between gap-2">
                <h2 id="deposit-heading" className="font-medium">
                    {t('Deposit')} · {deposit.deposit}
                </h2>
                {link && <DepositStatus row={link} />}
            </div>

            {link?.status === 'paid' && (
                <p>
                    {t(':amount by :method · receipt :receipt', {
                        amount: link.paid ?? '',
                        method: link.method ?? '',
                        receipt: link.receipt ?? '',
                    })}
                    <span className="block text-xs text-muted-foreground">
                        {link.via === 'manual'
                            ? t('Confirmed by :name on :at', {
                                  name: link.confirmed_by ?? '—',
                                  at: link.paid_at ?? '',
                              })
                            : t('Confirmed by the payment provider on :at', {
                                  at: link.paid_at ?? '',
                              })}
                    </span>
                </p>
            )}

            {link?.status === 'open' && (
                <p className="text-muted-foreground">
                    {link.sent_at
                        ? t('Link sent on :at; rooms held until :until.', {
                              at: link.sent_at,
                              until: link.expires_at ?? '—',
                          })
                        : t('Rooms held until :until.', {
                              until: link.expires_at ?? '—',
                          })}
                    {link.reminded_at &&
                        ' ' +
                            t('Reminder sent on :at.', {
                                at: link.reminded_at,
                            })}
                </p>
            )}

            {link?.refund_status === 'due' && (
                <p className="text-destructive">
                    {t(
                        ':amount to refund. Record it on the Deposits page once sent back.',
                        { amount: link.refund_due ?? '' },
                    )}
                </p>
            )}

            {!deposit.online && deposit.can_confirm && (
                <p className="text-muted-foreground">
                    {t(
                        'Online payment is off: confirm the deposit here once you see it in your account.',
                    )}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                {deposit.online && deposit.can_send && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `/hotel/bookings/${bookingId}/deposit/send`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Send aria-hidden />
                        {link?.sent_at
                            ? t('Send the link again')
                            : t('Send the payment link')}
                    </Button>
                )}
                {link?.url && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            void navigator.clipboard
                                ?.writeText(link.url ?? '')
                                .then(() => setCopied(true));
                        }}
                    >
                        {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
                        {copied ? t('Copied') : t('Copy the link')}
                    </Button>
                )}
                {deposit.can_confirm && !confirming && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setConfirming(true)}
                    >
                        <Smartphone aria-hidden />
                        {t('Money received')}
                    </Button>
                )}
            </div>

            {confirming && (
                <form
                    className="flex flex-col gap-2 rounded-lg border p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        confirm.transform((data) => ({
                            method: data.method,
                            amount_minor: Number(data.amount_minor) || null,
                        }));
                        confirm.post(
                            `/hotel/bookings/${bookingId}/deposit/confirm`,
                            {
                                preserveScroll: true,
                                onSuccess: () => setConfirming(false),
                            },
                        );
                    }}
                >
                    <Heading
                        variant="small"
                        title={t('Confirm the deposit')}
                        description={t(
                            'Only once you see the money in your account. The booking is confirmed and the guest gets the receipt.',
                        )}
                    />
                    <div className="flex flex-wrap gap-2">
                        <label className="grid gap-1">
                            <span>{t('How')}</span>
                            <select
                                className={selectClass}
                                value={confirm.data.method}
                                onChange={(e) =>
                                    confirm.setData('method', e.target.value)
                                }
                            >
                                {deposit.methods.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid flex-1 gap-1">
                            <span>{t('Amount received')}</span>
                            <Input
                                inputMode="numeric"
                                value={confirm.data.amount_minor}
                                onChange={(e) =>
                                    confirm.setData(
                                        'amount_minor',
                                        e.target.value.replace(/\D/g, ''),
                                    )
                                }
                            />
                        </label>
                    </div>
                    <InputError message={errors.deposit ?? errors.method} />
                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            disabled={confirm.processing}
                        >
                            {t('Confirm the deposit')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setConfirming(false)}
                        >
                            {t('Cancel')}
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}
