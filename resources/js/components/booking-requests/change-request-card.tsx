import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { askConfirm } from '@/lib/ask';
import { t } from '@/lib/i18n';

export type ChangeRequest = {
    id: string;
    kind: 'change' | 'cancellation';
    booking_id: string;
    reference: string;
    guest: string | null;
    category: string | null;
    check_in: string;
    check_out: string;
    summary: string;
    current_total: string;
    new_total: string | null;
    asked_at: string | null;
    conversation_id: string | null;
};

/**
 * 0.39.3: a guest's change or cancellation of their booking, asked on
 * WhatsApp. Apply makes it and tells the guest; Decline needs a reason,
 * which the guest receives.
 */
export function ChangeRequestCard({ request }: { request: ChangeRequest }) {
    const [declining, setDeclining] = useState(false);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const cancellation = request.kind === 'cancellation';

    const apply = async () => {
        if (
            !(await askConfirm({
                title: cancellation
                    ? t('Cancel booking :reference?', {
                          reference: request.reference,
                      })
                    : t('Apply the change to :reference?', {
                          reference: request.reference,
                      }),
                description: t('The guest is told on WhatsApp.'),
                confirmLabel: cancellation
                    ? t('Cancel the booking')
                    : t('Apply the change'),
                destructive: cancellation,
            }))
        ) {
            return;
        }
        setBusy(true);
        router.post(
            `/hotel/change-requests/${request.id}/apply`,
            {},
            {
                preserveScroll: true,
                onError: (errors) => setError(errors.change ?? null),
                onFinish: () => setBusy(false),
            },
        );
    };

    const decline = () => {
        if (reason.trim() === '') {
            return;
        }
        setBusy(true);
        router.post(
            `/hotel/change-requests/${request.id}/decline`,
            { reason: reason.trim() },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setError(errors.reason ?? errors.change ?? null),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-2 rounded-lg border p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant={cancellation ? 'destructive' : 'secondary'}>
                    {cancellation ? t('Cancellation') : t('Change')}
                </Badge>
                <Link
                    href={`/hotel/bookings/${request.booking_id}`}
                    className="font-medium underline-offset-2 hover:underline"
                >
                    {request.reference}
                </Link>
                <span>{request.guest}</span>
                {request.asked_at && (
                    <span className="text-xs text-muted-foreground">
                        {t('Asked :time', { time: request.asked_at })}
                    </span>
                )}
            </div>
            <p className="text-muted-foreground">
                {t('Booked: :category, :in → :out, total :total', {
                    category: request.category ?? '',
                    in: request.check_in,
                    out: request.check_out,
                    total: request.current_total,
                })}
            </p>
            {cancellation ? (
                <p>
                    {t('The guest asks to cancel (:summary).', {
                        summary: request.summary,
                    })}
                </p>
            ) : (
                <p>
                    {t('The guest asks for: :summary. New total: :total', {
                        summary: request.summary,
                        total: request.new_total ?? '',
                    })}
                </p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <Button size="sm" disabled={busy} onClick={() => void apply()}>
                    {cancellation ? t('Cancel the booking') : t('Apply')}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={busy}
                    onClick={() => setDeclining(!declining)}
                >
                    {t('Decline')}
                </Button>
                {request.conversation_id && (
                    <Link
                        href={`/hotel/inbox/${request.conversation_id}`}
                        className="text-xs underline-offset-2 hover:underline"
                    >
                        {t('Open the chat')}
                    </Link>
                )}
            </div>
            {declining && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        autoFocus
                        className="min-w-56 flex-1"
                        placeholder={t('Why (the guest receives this)')}
                        aria-label={t('Why (the guest receives this)')}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                    <Button
                        size="sm"
                        variant="destructive"
                        disabled={busy || reason.trim() === ''}
                        onClick={decline}
                    >
                        {t('Decline and tell the guest')}
                    </Button>
                </div>
            )}
            <InputError message={error ?? undefined} />
        </div>
    );
}
