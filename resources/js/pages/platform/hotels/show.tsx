import { Head, Link, router, useForm } from '@inertiajs/react';
import AccountHotelsController from '@/actions/App/Domains/Platform/Http/Controllers/AccountHotelsController';
import PlatformLifecycleController from '@/actions/App/Domains/Platform/Http/Controllers/PlatformLifecycleController';
import AuditLogController from '@/actions/App/Domains/Platform/Http/Controllers/AuditLogController';
import HotelController from '@/actions/App/Domains/Platform/Http/Controllers/HotelController';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import PlatformBillingController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformBillingController';
import SupportSessionController from '@/actions/App/Domains/Platform/Http/Controllers/SupportSessionController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type Credit = { granted: number; used: number; left: number } | null;
type Props = {
    hotel: {
        id: string;
        name: string;
        country: string;
        currency: string;
        timezone: string;
        signup_source: string;
        created_at: string | null;
        owner: { name: string; email: string; phone: string | null } | null;
    };
    health: {
        status: string;
        mode: string;
        plan: string | null;
        subscription_status: string | null;
        trial_ends: string | null;
        whatsapp: {
            status: string;
            phone: string | null;
            quality: string | null;
            limit: string | null;
            last_error: string | null;
            token_checked_at: string | null;
        } | null;
        last_webhook: string | null;
        ai_failure_rate: number | null;
        credits: { ai: Credit; email: Credit } | null;
        setup: { done: number; total: number; complete: boolean };
        usage: {
            conversations_month: number;
            messages_month: number;
            bookings_month: number;
            handovers_month: number;
        };
        issues: string[];
    };
    issueLabels: string[];
    staff: {
        name: string;
        email: string;
        status: string | null;
        last_login: string | null;
    }[];
    history: {
        id: number;
        event: string | null;
        description: string;
        by: string | null;
        at: string | null;
    }[];
    notes: {
        id: number;
        body: string;
        by: string | null;
        at: string | null;
        mine: boolean;
    }[];
    supportSessions: {
        id: string;
        by: string | null;
        mine: boolean;
        reason: string;
        mode: string;
        status: string;
        live: boolean;
        at: string | null;
    }[];
    closure: {
        closed_at: string;
        delete_after: string;
        by: string;
        reason: string | null;
        export: boolean;
    } | null;
    can: {
        support: boolean;
        manage: boolean;
        billing: boolean;
        audit: boolean;
        lifecycle: boolean;
    };
};

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex justify-between gap-3 border-b py-1 last:border-0">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right">{value ?? '—'}</span>
        </div>
    );
}

const credit = (c: Credit) =>
    c ? `${Math.floor(c.left / 100)} / ${Math.floor(c.granted / 100)}` : '—';

function HelpThisHotel({
    hotelId,
    sessions,
}: {
    hotelId: string;
    sessions: Props['supportSessions'];
}) {
    const form = useForm({ reason: '', mode: 'read' });

    return (
        <section className="flex flex-col gap-2 text-sm">
            <Heading
                variant="small"
                title={t('Help this hotel')}
                description={t(
                    'Open the hotel’s screens for 30 minutes. The owner is told, and everything you do is recorded in the hotel’s history.',
                )}
            />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(SupportSessionController.start.url(hotelId));
                }}
                className="flex flex-wrap items-end gap-2"
            >
                <input
                    aria-label={t('Reason')}
                    placeholder={t(
                        'Reason (e.g. owner called: rates not showing)',
                    )}
                    className="h-9 min-w-72 flex-1 rounded-md border border-input bg-transparent px-3"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
                <select
                    aria-label={t('Access')}
                    className="flex h-9 rounded-md border border-input bg-transparent px-3"
                    value={form.data.mode}
                    onChange={(e) => form.setData('mode', e.target.value)}
                >
                    <option value="read">{t('Read-only')}</option>
                    <option value="write">{t('Can make changes')}</option>
                </select>
                <Button
                    size="sm"
                    disabled={
                        form.processing || form.data.reason.trim().length < 5
                    }
                >
                    {t('Start session')}
                </Button>
            </form>
            {sessions.length > 0 && (
                <ul className="divide-y rounded-xl border">
                    {sessions.map((s) => (
                        <li
                            key={s.id}
                            className="flex flex-wrap items-center gap-2 px-3 py-1.5"
                        >
                            <span className="text-xs text-muted-foreground">
                                {s.at}
                            </span>
                            <span>{s.by}</span>
                            <span className="flex-1">{s.reason}</span>
                            <Badge variant="outline">{s.mode}</Badge>
                            <Badge variant={s.live ? 'default' : 'secondary'}>
                                {s.live ? t('In progress') : s.status}
                            </Badge>
                            {s.mine && s.live && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            SupportSessionController.enter.url(
                                                s.id,
                                            ),
                                        )
                                    }
                                >
                                    {t('Enter')}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function HotelShow({
    hotel,
    health,
    issueLabels,
    staff,
    history,
    notes,
    supportSessions,
    closure,
    can,
}: Props) {
    const form = useForm({ body: '' });

    return (
        <>
            <Head title={hotel.name} />
            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Heading
                        title={hotel.name}
                        description={`${hotel.country} · ${hotel.currency} · ${hotel.timezone} · ${t('since :date', { date: hotel.created_at ?? '' })}`}
                    />
                    <Badge
                        variant={
                            health.status === 'suspended'
                                ? 'destructive'
                                : 'secondary'
                        }
                    >
                        {health.status}
                    </Badge>
                    {issueLabels.map((l) => (
                        <Badge key={l} variant="destructive">
                            {l}
                        </Badge>
                    ))}
                    <span className="ml-auto flex gap-3 text-sm">
                        {can.manage && (
                            <Link
                                href={HotelController.edit(hotel.id)}
                                className="underline"
                            >
                                {t('Edit')}
                            </Link>
                        )}
                        {can.billing && (
                            <Link
                                href={PlatformBillingController.hotel(hotel.id)}
                                className="underline"
                            >
                                {t('Billing')}
                            </Link>
                        )}
                        {can.audit && (
                            <Link
                                href={AuditLogController.index({
                                    query: { hotel: hotel.id },
                                })}
                                className="underline"
                            >
                                {t('Full history')}
                            </Link>
                        )}
                    </span>
                </div>

                <div className="grid gap-4 text-sm md:grid-cols-3">
                    <section className="rounded-xl border p-3">
                        <Heading variant="small" title={t('Overview')} />
                        <Row
                            label={t('Owner')}
                            value={
                                hotel.owner
                                    ? `${hotel.owner.name} · ${hotel.owner.email}`
                                    : null
                            }
                        />
                        <Row label={t('Phone')} value={hotel.owner?.phone} />
                        <Row
                            label={t('Signed up')}
                            value={hotel.signup_source}
                        />
                        <Row
                            label={t('Plan')}
                            value={
                                health.plan
                                    ? `${health.plan} (${health.subscription_status})`
                                    : null
                            }
                        />
                        <Row
                            label={t('Trial until')}
                            value={health.trial_ends}
                        />
                        <Row label={t('Assistant')} value={health.mode} />
                        <Row
                            label={t('Setup')}
                            value={`${health.setup.done}/${health.setup.total}`}
                        />
                    </section>
                    <section className="rounded-xl border p-3">
                        <Heading variant="small" title={t('Health')} />
                        <Row
                            label="WhatsApp"
                            value={
                                health.whatsapp
                                    ? `${health.whatsapp.status} · ${health.whatsapp.phone ?? ''}`
                                    : t('Not connected')
                            }
                        />
                        <Row
                            label={t('Quality / limit')}
                            value={
                                health.whatsapp
                                    ? `${health.whatsapp.quality ?? '—'} / ${health.whatsapp.limit ?? '—'}`
                                    : null
                            }
                        />
                        <Row
                            label={t('Last WhatsApp error')}
                            value={health.whatsapp?.last_error}
                        />
                        <Row
                            label={t('Last notification')}
                            value={health.last_webhook}
                        />
                        <Row
                            label={t('AI failures this month')}
                            value={
                                health.ai_failure_rate === null
                                    ? null
                                    : `${health.ai_failure_rate}%`
                            }
                        />
                        <Row
                            label={t('AI credits left')}
                            value={credit(health.credits?.ai ?? null)}
                        />
                        <Row
                            label={t('Email credits left')}
                            value={credit(health.credits?.email ?? null)}
                        />
                    </section>
                    <section className="rounded-xl border p-3">
                        <Heading variant="small" title={t('This month')} />
                        <Row
                            label={t('Conversations')}
                            value={health.usage.conversations_month}
                        />
                        <Row
                            label={t('Messages')}
                            value={health.usage.messages_month}
                        />
                        <Row
                            label={t('Bookings')}
                            value={health.usage.bookings_month}
                        />
                        <Row
                            label={t('Passed to staff')}
                            value={health.usage.handovers_month}
                        />
                    </section>
                </div>

                {closure && (
                    <p className="rounded-lg border border-destructive px-3 py-2 text-sm text-destructive">
                        {t('Closed on :closed (:by). Data deleted on :date.', {
                            closed: closure.closed_at,
                            by: closure.by,
                            date: closure.delete_after,
                        })}{' '}
                        {closure.reason}
                    </p>
                )}
                {can.lifecycle && (
                    <div className="flex gap-2 text-sm">
                        {closure ? (
                            <>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            PlatformLifecycleController.reopen.url(
                                                hotel.id,
                                            ),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {t('Reopen')}
                                </Button>
                                {closure.export && (
                                    <a
                                        href={AccountHotelsController.export.url(
                                            hotel.id,
                                        )}
                                        className="self-center underline"
                                    >
                                        {t('Download all data')}
                                    </a>
                                )}
                            </>
                        ) : (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    const reason = window.prompt(
                                        t('Close this hotel? Reason:'),
                                    );
                                    if (reason) {
                                        router.post(
                                            PlatformLifecycleController.close.url(
                                                hotel.id,
                                            ),
                                            { reason },
                                            { preserveScroll: true },
                                        );
                                    }
                                }}
                            >
                                {t('Close hotel…')}
                            </Button>
                        )}
                    </div>
                )}
                {can.support && (
                    <HelpThisHotel
                        hotelId={hotel.id}
                        sessions={supportSessions}
                    />
                )}

                <section className="flex flex-col gap-2 text-sm">
                    <Heading
                        variant="small"
                        title={t('Internal notes')}
                        description={t(
                            'Only the platform team sees these notes.',
                        )}
                    />
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(
                                HotelOverviewController.storeNote.url(hotel.id),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset(),
                                },
                            );
                        }}
                        className="flex gap-2"
                    >
                        <textarea
                            aria-label={t('Note')}
                            className="min-h-14 flex-1 rounded-md border border-input bg-transparent px-3 py-2"
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                        />
                        <Button
                            size="sm"
                            disabled={
                                form.processing || form.data.body.trim() === ''
                            }
                        >
                            {t('Add note')}
                        </Button>
                    </form>
                    <ul className="divide-y rounded-xl border">
                        {notes.map((n) => (
                            <li key={n.id} className="flex gap-2 px-3 py-2">
                                <span className="flex-1 whitespace-pre-wrap">
                                    {n.body}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {n.by} · {n.at}
                                </span>
                                {n.mine && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(
                                                HotelOverviewController.destroyNote.url(
                                                    {
                                                        hotel: hotel.id,
                                                        note: n.id,
                                                    },
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('Remove')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                <div className="grid gap-4 text-sm md:grid-cols-2">
                    <section className="flex flex-col gap-2">
                        <Heading variant="small" title={t('Staff')} />
                        <ul className="divide-y rounded-xl border">
                            {staff.map((s) => (
                                <li
                                    key={s.email}
                                    className="flex flex-wrap gap-2 px-3 py-1.5"
                                >
                                    <span className="font-medium">
                                        {s.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {s.email}
                                    </span>
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        {s.last_login
                                            ? t('last login :date', {
                                                  date: s.last_login,
                                              })
                                            : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                    <section className="flex flex-col gap-2">
                        <Heading variant="small" title={t('Recent history')} />
                        <ul className="divide-y rounded-xl border">
                            {history.map((h) => (
                                <li
                                    key={h.id}
                                    className="flex flex-wrap gap-2 px-3 py-1.5"
                                >
                                    <span className="w-36 text-xs text-muted-foreground">
                                        {h.at}
                                    </span>
                                    <span className="flex-1">
                                        {h.description}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {h.by}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            </div>
        </>
    );
}

HotelShow.layout = {
    breadcrumbs: [{ title: 'Hotels', href: HotelController.index() }],
};
