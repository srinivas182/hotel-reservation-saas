import { Head, Link, router, useForm } from '@inertiajs/react';
import { CreditCard, History, Pencil } from 'lucide-react';
import AccountHotelsController from '@/actions/App/Domains/Platform/Http/Controllers/AccountHotelsController';
import PlatformLifecycleController from '@/actions/App/Domains/Platform/Http/Controllers/PlatformLifecycleController';
import AuditLogController from '@/actions/App/Domains/Platform/Http/Controllers/AuditLogController';
import HotelController from '@/actions/App/Domains/Platform/Http/Controllers/HotelController';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import PlatformBillingController from '@/actions/App/Domains/Billing/Http/Controllers/PlatformBillingController';
import SupportSessionController from '@/actions/App/Domains/Platform/Http/Controllers/SupportSessionController';
import ManualWhatsAppController from '@/actions/App/Domains/Platform/Http/Controllers/ManualWhatsAppController';
import InputError from '@/components/input-error';
import {
    DataTable,
    Field,
    PageBody,
    Section,
    StatTile,
    Tabs,
    useTabs,
} from '@/components/page';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { askText } from '@/lib/ask';

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
        manualWhatsApp: boolean;
    };
};

/**
 * Super admins: connect a number with a Meta system-user token (Meta's test
 * number or the platform's own numbers), without Embedded Signup.
 */
function ManualWhatsApp({ hotelId }: { hotelId: string }) {
    const form = useForm({ phone_number_id: '', waba_id: '', token: '' });

    return (
        <details className="rounded-xl border bg-card px-4 py-3 text-sm">
            <summary className="cursor-pointer font-medium">
                {t('Connect a WhatsApp number manually (testing)')}
            </summary>
            <p className="mt-2 text-xs text-muted-foreground">
                {t(
                    'For Meta’s test number or a number in your own WhatsApp Business account. In Meta: WhatsApp → API Setup shows the Phone Number ID and the WhatsApp Business account ID; the token comes from Business settings → System users (permissions whatsapp_business_messaging and whatsapp_business_management, never expires). Hotels normally connect their own number from Hotel → WhatsApp.',
                )}
            </p>
            <form
                className="mt-3 grid gap-3 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(ManualWhatsAppController.store.url(hotelId), {
                        preserveScroll: true,
                        onSuccess: () => form.reset('token'),
                    });
                }}
            >
                <label className="grid gap-1.5 text-xs font-medium">
                    {t('Phone Number ID')}
                    <Input
                        inputMode="numeric"
                        value={form.data.phone_number_id}
                        onChange={(e) =>
                            form.setData('phone_number_id', e.target.value)
                        }
                    />
                    <InputError message={form.errors.phone_number_id} />
                </label>
                <label className="grid gap-1.5 text-xs font-medium">
                    {t('WhatsApp Business account ID')}
                    <Input
                        inputMode="numeric"
                        value={form.data.waba_id}
                        onChange={(e) =>
                            form.setData('waba_id', e.target.value)
                        }
                    />
                    <InputError message={form.errors.waba_id} />
                </label>
                <label className="grid gap-1.5 text-xs font-medium sm:col-span-2">
                    {t('System user access token')}
                    <Input
                        type="password"
                        autoComplete="off"
                        value={form.data.token}
                        onChange={(e) => form.setData('token', e.target.value)}
                    />
                    <InputError message={form.errors.token} />
                </label>
                <div className="sm:col-span-2">
                    <Button size="sm" disabled={form.processing}>
                        {form.processing ? t('Connecting…') : t('Connect')}
                    </Button>
                </div>
            </form>
        </details>
    );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-3 py-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-right break-words">{value ?? '—'}</dd>
        </div>
    );
}

function Card({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card px-5 py-4">
            <h2 className="mb-1 font-medium">{title}</h2>
            <dl className="divide-y text-sm">{children}</dl>
        </section>
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
        <Section
            title={t('Help this hotel')}
            description={t(
                'Open the hotel’s screens for 30 minutes. The owner is told, and everything you do is recorded in the hotel’s history.',
            )}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(SupportSessionController.start.url(hotelId));
                }}
                className="grid items-end gap-3 sm:grid-cols-[1fr_12rem_auto]"
            >
                <Field label={t('Reason')}>
                    <Input
                        placeholder={t(
                            'Reason (e.g. owner called: rates not showing)',
                        )}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                </Field>
                <Field label={t('Access')}>
                    <select
                        className="h-9"
                        value={form.data.mode}
                        onChange={(e) => form.setData('mode', e.target.value)}
                    >
                        <option value="read">{t('Read-only')}</option>
                        <option value="write">{t('Can make changes')}</option>
                    </select>
                </Field>
                <Button
                    disabled={
                        form.processing || form.data.reason.trim().length < 5
                    }
                >
                    {t('Start session')}
                </Button>
            </form>
            {sessions.length > 0 && (
                <div className="-mx-5 mt-5 -mb-5 border-t">
                    <DataTable
                        rows={sessions}
                        rowKey={(s) => s.id}
                        empty="—"
                        columns={[
                            {
                                key: 'reason',
                                header: t('Reason'),
                                cell: (s) => (
                                    <div>
                                        <div>{s.reason}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {s.by} · {s.at}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'mode',
                                header: t('Access'),
                                cell: (s) => (
                                    <Badge variant="outline">{s.mode}</Badge>
                                ),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (s) => (
                                    <Badge
                                        variant={
                                            s.live ? 'default' : 'secondary'
                                        }
                                    >
                                        {s.live ? t('In progress') : s.status}
                                    </Badge>
                                ),
                            },
                            {
                                key: 'enter',
                                header: '',
                                align: 'right',
                                cell: (s) =>
                                    s.mine &&
                                    s.live && (
                                        <Button
                                            size="sm"
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
                                    ),
                            },
                        ]}
                    />
                </div>
            )}
        </Section>
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
    const tabs = [
        { key: 'overview', label: t('Overview') },
        { key: 'staff', label: t('Staff'), count: staff.length },
        { key: 'notes', label: t('Internal notes'), count: notes.length },
        { key: 'history', label: t('Recent history') },
        ...(can.support || can.lifecycle || can.manualWhatsApp
            ? [{ key: 'support', label: t('Support and access') }]
            : []),
    ];
    const [tab, setTab] = useTabs(tabs);
    const wa = health.whatsapp;

    return (
        <>
            <Head title={hotel.name} />
            <PageBody>
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 items-start gap-4">
                        <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-lg font-semibold text-brand">
                            {hotel.name.charAt(0).toUpperCase()}
                        </span>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="font-display text-2xl font-extrabold tracking-tight">
                                    {hotel.name}
                                </h1>
                                <Badge
                                    variant={
                                        health.status === 'suspended'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                >
                                    {health.status}
                                </Badge>
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {hotel.country} · {hotel.currency} ·{' '}
                                {hotel.timezone} ·{' '}
                                {t('since :date', {
                                    date: hotel.created_at ?? '',
                                })}
                            </p>
                            {issueLabels.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {issueLabels.map((l) => (
                                        <Badge key={l} variant="destructive">
                                            {l}
                                        </Badge>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can.audit && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={AuditLogController.index({
                                        query: { hotel: hotel.id },
                                    })}
                                >
                                    <History /> {t('Full history')}
                                </Link>
                            </Button>
                        )}
                        {can.billing && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={PlatformBillingController.hotel(
                                        hotel.id,
                                    )}
                                >
                                    <CreditCard /> {t('Billing')}
                                </Link>
                            </Button>
                        )}
                        {can.manage && (
                            <Button asChild>
                                <Link href={HotelController.edit(hotel.id)}>
                                    <Pencil /> {t('Edit')}
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {closure && (
                    <div
                        role="alert"
                        className="rounded-xl border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                    >
                        {t('Closed on :closed (:by). Data deleted on :date.', {
                            closed: closure.closed_at,
                            by: closure.by,
                            date: closure.delete_after,
                        })}{' '}
                        {closure.reason}
                    </div>
                )}

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatTile
                        label={t('Setup')}
                        value={`${health.setup.done}/${health.setup.total}`}
                        hint={health.setup.complete ? t('Complete') : undefined}
                    />
                    <StatTile
                        label="WhatsApp"
                        value={
                            <span
                                className={cn(
                                    'text-base',
                                    wa?.status === 'connected'
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-destructive',
                                )}
                            >
                                {wa ? wa.status : t('Not connected')}
                            </span>
                        }
                        hint={wa?.phone ?? undefined}
                    />
                    <StatTile
                        label={t('Conversations this month')}
                        value={health.usage.conversations_month}
                        hint={t(':count bookings', {
                            count: health.usage.bookings_month,
                        })}
                    />
                    <StatTile
                        label={t('AI credits left')}
                        value={credit(health.credits?.ai ?? null)}
                    />
                </div>

                <Tabs tabs={tabs} active={tab} onSelect={setTab} />

                {tab === 'overview' && (
                    <div className="grid gap-4 lg:grid-cols-3">
                        <Card title={t('Account')}>
                            <Row
                                label={t('Owner')}
                                value={
                                    hotel.owner && (
                                        <>
                                            <div>{hotel.owner.name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {hotel.owner.email}
                                            </div>
                                        </>
                                    )
                                }
                            />
                            <Row
                                label={t('Phone')}
                                value={hotel.owner?.phone}
                            />
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
                        </Card>
                        <Card title={t('Health')}>
                            <Row
                                label={t('Quality / limit')}
                                value={
                                    wa
                                        ? `${wa.quality ?? '—'} / ${wa.limit ?? '—'}`
                                        : null
                                }
                            />
                            <Row
                                label={t('Last WhatsApp error')}
                                value={
                                    wa?.last_error && (
                                        <span className="text-destructive">
                                            {wa.last_error}
                                        </span>
                                    )
                                }
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
                                label={t('Email credits left')}
                                value={credit(health.credits?.email ?? null)}
                            />
                        </Card>
                        <Card title={t('This month')}>
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
                        </Card>
                    </div>
                )}

                {tab === 'staff' && (
                    <Section flush title={t('Staff')}>
                        <DataTable
                            rows={staff}
                            rowKey={(s) => s.email}
                            empty={t('No staff yet.')}
                            columns={[
                                {
                                    key: 'name',
                                    header: t('Name'),
                                    cell: (s) => (
                                        <div>
                                            <div className="font-medium">
                                                {s.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {s.email}
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (s) =>
                                        s.status ? (
                                            <Badge variant="outline">
                                                {s.status}
                                            </Badge>
                                        ) : (
                                            '—'
                                        ),
                                },
                                {
                                    key: 'login',
                                    header: t('Last sign-in'),
                                    cell: (s) => (
                                        <span className="text-muted-foreground">
                                            {s.last_login ?? t('Never')}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'notes' && (
                    <Section
                        title={t('Internal notes')}
                        description={t(
                            'Only the platform team sees these notes.',
                        )}
                    >
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(
                                    HotelOverviewController.storeNote.url(
                                        hotel.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => form.reset(),
                                    },
                                );
                            }}
                            className="grid gap-2"
                        >
                            <textarea
                                aria-label={t('Note')}
                                className="min-h-20"
                                placeholder={t('Write a note for the team…')}
                                value={form.data.body}
                                onChange={(e) =>
                                    form.setData('body', e.target.value)
                                }
                            />
                            <div className="flex justify-end">
                                <Button
                                    size="sm"
                                    disabled={
                                        form.processing ||
                                        form.data.body.trim() === ''
                                    }
                                >
                                    {t('Add note')}
                                </Button>
                            </div>
                        </form>
                        {notes.length > 0 && (
                            <ul className="mt-4 grid gap-3">
                                {notes.map((n) => (
                                    <li
                                        key={n.id}
                                        className="rounded-lg border bg-muted/30 px-4 py-3 text-sm"
                                    >
                                        <p className="whitespace-pre-wrap">
                                            {n.body}
                                        </p>
                                        <div className="mt-2 flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                            <span>
                                                {n.by} · {n.at}
                                            </span>
                                            {n.mine && (
                                                <button
                                                    type="button"
                                                    className="text-destructive hover:underline"
                                                    onClick={() =>
                                                        router.delete(
                                                            HotelOverviewController.destroyNote.url(
                                                                {
                                                                    hotel: hotel.id,
                                                                    note: n.id,
                                                                },
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {t('Remove')}
                                                </button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                )}

                {tab === 'history' && (
                    <Section
                        flush
                        title={t('Recent history')}
                        actions={
                            can.audit && (
                                <Link
                                    href={AuditLogController.index({
                                        query: { hotel: hotel.id },
                                    })}
                                    className="text-sm text-brand hover:underline"
                                >
                                    {t('Full history')}
                                </Link>
                            )
                        }
                    >
                        <DataTable
                            rows={history}
                            rowKey={(h) => h.id}
                            empty={t('Nothing yet.')}
                            columns={[
                                {
                                    key: 'what',
                                    header: t('What'),
                                    cell: (h) => h.description,
                                },
                                {
                                    key: 'by',
                                    header: t('By'),
                                    cell: (h) => h.by ?? '—',
                                },
                                {
                                    key: 'at',
                                    header: t('When'),
                                    cell: (h) => (
                                        <span className="text-xs whitespace-nowrap text-muted-foreground">
                                            {h.at}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'support' && (
                    <>
                        {can.support && (
                            <HelpThisHotel
                                hotelId={hotel.id}
                                sessions={supportSessions}
                            />
                        )}
                        {can.manualWhatsApp && (
                            <ManualWhatsApp hotelId={hotel.id} />
                        )}
                        {can.lifecycle && (
                            <section className="rounded-xl border border-destructive/30 bg-card px-5 py-4">
                                <h2 className="font-medium text-destructive">
                                    {closure
                                        ? t('Closed hotel')
                                        : t('Close the hotel')}
                                </h2>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {closure
                                        ? t(
                                              'Reopen it before the deletion date to keep its data.',
                                          )
                                        : t(
                                              'Staff lose access at once; the data is kept for a while, then deleted.',
                                          )}
                                </p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {closure ? (
                                        <>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        PlatformLifecycleController.reopen.url(
                                                            hotel.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Reopen')}
                                            </Button>
                                            {closure.export && (
                                                <Button variant="ghost" asChild>
                                                    <a
                                                        href={AccountHotelsController.export.url(
                                                            hotel.id,
                                                        )}
                                                    >
                                                        {t('Download all data')}
                                                    </a>
                                                </Button>
                                            )}
                                        </>
                                    ) : (
                                        <Button
                                            variant="destructive"
                                            onClick={async () => {
                                                const reason = await askText({
                                                    title: t(
                                                        'Close this hotel? Reason:',
                                                    ),
                                                    required: true,
                                                });
                                                if (reason) {
                                                    router.post(
                                                        PlatformLifecycleController.close.url(
                                                            hotel.id,
                                                        ),
                                                        { reason },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            {t('Close hotel…')}
                                        </Button>
                                    )}
                                </div>
                            </section>
                        )}
                    </>
                )}
            </PageBody>
        </>
    );
}

HotelShow.layout = {
    breadcrumbs: [{ title: 'Hotels', href: HotelController.index() }],
};
