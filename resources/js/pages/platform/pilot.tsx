import { Head, router } from '@inertiajs/react';
import PilotDashboardController from '@/actions/App/Domains/Platform/Http/Controllers/PilotDashboardController';
import { EmptyState, PageBody, PageHeader, StatTile } from '@/components/page';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Metrics = {
    guest_messages: number;
    conversations: number;
    fast_reply_rate: number | null;
    without_staff_rate: number | null;
    requests: number;
    median_decision_minutes: number | null;
    request_to_booking_rate: number | null;
    drafts: { sent_unchanged: number; edited: number; discarded: number };
    handovers: Record<string, number>;
    safety_blocks: number;
    ai_cost_usd: number;
    ai_cost_per_conversation_usd: number | null;
    ai_cost_per_booking_usd: number | null;
    whatsapp: { service: number; templates: number };
};

type Props = {
    days: number;
    hotels: { id: string; name: string; metrics: Metrics }[];
    incidents: { title: string; detail: string | null; since: string }[];
    backup: { status: string; at: string | null; off_site: boolean } | null;
    drill: { status: string; at: string | null } | null;
};

const pct = (value: number | null) => (value === null ? '—' : `${value}%`);

type Row = {
    key: string;
    label: string;
    group: string;
    value: (m: Metrics) => string | number;
};

export default function Pilot({
    days,
    hotels,
    incidents,
    backup,
    drill,
}: Props) {
    const rows: Row[] = [
        {
            key: 'conv',
            group: t('Guests'),
            label: t('Conversations'),
            value: (m) => m.conversations,
        },
        {
            key: 'fast',
            group: t('Guests'),
            label: t('Replied within 1 minute'),
            value: (m) => pct(m.fast_reply_rate),
        },
        {
            key: 'alone',
            group: t('Guests'),
            label: t('Handled without staff'),
            value: (m) => pct(m.without_staff_rate),
        },
        {
            key: 'req',
            group: t('Bookings'),
            label: t('Booking requests'),
            value: (m) => m.requests,
        },
        {
            key: 'decide',
            group: t('Bookings'),
            label: t('Median time to decide (min)'),
            value: (m) => m.median_decision_minutes ?? '—',
        },
        {
            key: 'conversion',
            group: t('Bookings'),
            label: t('Requests that became bookings'),
            value: (m) => pct(m.request_to_booking_rate),
        },
        {
            key: 'drafts',
            group: t('Assistant'),
            label: t('Drafts sent unchanged / edited / discarded'),
            value: (m) =>
                `${m.drafts.sent_unchanged} / ${m.drafts.edited} / ${m.drafts.discarded}`,
        },
        {
            key: 'safety',
            group: t('Assistant'),
            label: t('Safety blocks'),
            value: (m) => m.safety_blocks,
        },
        {
            key: 'handovers',
            group: t('Assistant'),
            label: t('Passed to staff:'),
            value: (m) =>
                Object.entries(m.handovers)
                    .map(([r, c]) => `${r} ${c}`)
                    .join(' · ') || '—',
        },
        {
            key: 'cost',
            group: t('Costs'),
            label: t('AI cost (US$)'),
            value: (m) => m.ai_cost_usd,
        },
        {
            key: 'cost_conv',
            group: t('Costs'),
            label: t('AI cost per conversation'),
            value: (m) => m.ai_cost_per_conversation_usd ?? '—',
        },
        {
            key: 'cost_booking',
            group: t('Costs'),
            label: t('AI cost per booking'),
            value: (m) => m.ai_cost_per_booking_usd ?? '—',
        },
        {
            key: 'wa',
            group: t('Costs'),
            label: t('WhatsApp replies / templates'),
            value: (m) => `${m.whatsapp.service} / ${m.whatsapp.templates}`,
        },
    ];
    const groups = [...new Set(rows.map((r) => r.group))];

    return (
        <>
            <Head title={t('Pilot dashboard')} />
            <PageBody>
                <PageHeader
                    title={t('Pilot dashboard')}
                    description={t('Last :days days', { days })}
                    actions={
                        <div
                            role="group"
                            aria-label={t('Period')}
                            className="inline-flex rounded-lg border bg-muted/40 p-0.5"
                        >
                            {[7, 14, 30].map((d) => (
                                <button
                                    key={d}
                                    type="button"
                                    aria-pressed={d === days}
                                    onClick={() =>
                                        router.get(
                                            PilotDashboardController.url({
                                                query: { days: d },
                                            }),
                                        )
                                    }
                                    className={cn(
                                        'rounded-md px-3 py-1 text-xs transition-colors',
                                        d === days
                                            ? 'bg-card font-medium shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {t(':days days', { days: d })}
                                </button>
                            ))}
                        </div>
                    }
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatTile
                        label={t('Operations problems')}
                        value={
                            <span
                                className={
                                    incidents.length > 0
                                        ? 'text-destructive'
                                        : 'text-emerald-600'
                                }
                            >
                                {incidents.length}
                            </span>
                        }
                        hint={
                            incidents.length === 0
                                ? t('No open operations problems')
                                : incidents
                                      .map((i) => `${i.title} (${i.since})`)
                                      .join(' · ')
                        }
                    />
                    <StatTile
                        label={t('Last backup')}
                        value={
                            <span
                                className={cn(
                                    'text-base',
                                    backup?.status !== 'success' &&
                                        'text-destructive',
                                )}
                            >
                                {backup?.status ?? t('none')}
                            </span>
                        }
                        hint={[
                            backup?.at,
                            backup?.off_site ? t('off-site') : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    />
                    <StatTile
                        label={t('Last restore drill')}
                        value={
                            <span
                                className={cn(
                                    'text-base',
                                    drill?.status !== 'success' &&
                                        'text-destructive',
                                )}
                            >
                                {drill?.status ?? t('none')}
                            </span>
                        }
                        hint={drill?.at ?? undefined}
                    />
                </div>

                <section className="overflow-hidden rounded-xl border bg-card">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-medium">
                            {t('Pilot hotels side by side')}
                        </h2>
                    </div>
                    {hotels.length === 0 ? (
                        <EmptyState text={t('No hotels found.')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[36rem] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                        <th className="px-5 py-2.5 font-medium">
                                            {t('Measure')}
                                        </th>
                                        {hotels.map((h) => (
                                            <th
                                                key={h.id}
                                                scope="col"
                                                className="px-5 py-2.5 text-right font-medium whitespace-nowrap"
                                            >
                                                {h.name}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                {groups.map((g) => (
                                    <tbody key={g} className="divide-y">
                                        <tr className="bg-muted/20">
                                            <th
                                                colSpan={hotels.length + 1}
                                                scope="colgroup"
                                                className="px-5 py-2 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                            >
                                                {g}
                                            </th>
                                        </tr>
                                        {rows
                                            .filter((r) => r.group === g)
                                            .map((r) => (
                                                <tr key={r.key}>
                                                    <th
                                                        scope="row"
                                                        className="px-5 py-2.5 text-left font-normal"
                                                    >
                                                        {r.label}
                                                    </th>
                                                    {hotels.map((h) => (
                                                        <td
                                                            key={h.id}
                                                            className="px-5 py-2.5 text-right tabular-nums"
                                                        >
                                                            {r.value(h.metrics)}
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                    </tbody>
                                ))}
                            </table>
                        </div>
                    )}
                </section>
            </PageBody>
        </>
    );
}

Pilot.layout = {
    breadcrumbs: [
        { title: 'Pilot dashboard', href: PilotDashboardController() },
    ],
};
