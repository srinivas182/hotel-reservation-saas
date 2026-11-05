import { Head, router } from '@inertiajs/react';
import PilotDashboardController from '@/actions/App/Domains/Platform/Http/Controllers/PilotDashboardController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

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

function Stat({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="rounded-lg border p-3">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="text-lg font-semibold">{value}</div>
        </div>
    );
}

export default function Pilot({
    days,
    hotels,
    incidents,
    backup,
    drill,
}: Props) {
    return (
        <>
            <Head title={t('Pilot dashboard')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Heading
                        title={t('Pilot dashboard')}
                        description={t('Last :days days', { days })}
                    />
                    <div className="flex gap-1">
                        {[7, 14, 30].map((d) => (
                            <Button
                                key={d}
                                size="sm"
                                variant={d === days ? 'default' : 'outline'}
                                onClick={() =>
                                    router.get(
                                        PilotDashboardController.url({
                                            query: { days: d },
                                        }),
                                    )
                                }
                            >
                                {t(':days days', { days: d })}
                            </Button>
                        ))}
                    </div>
                </div>

                <section className="flex flex-wrap gap-2 text-sm">
                    {incidents.length === 0 ? (
                        <Badge>{t('No open operations problems')}</Badge>
                    ) : (
                        incidents.map((i) => (
                            <Badge
                                key={i.title}
                                variant="destructive"
                            >{`${i.title} (${i.since})`}</Badge>
                        ))
                    )}
                    <Badge
                        variant={
                            backup?.status === 'success'
                                ? 'secondary'
                                : 'destructive'
                        }
                    >
                        {t('Last backup: :status :at', {
                            status: backup?.status ?? t('none'),
                            at: backup?.at ?? '',
                        })}
                        {backup?.off_site ? ` · ${t('off-site')}` : ''}
                    </Badge>
                    <Badge
                        variant={
                            drill?.status === 'success'
                                ? 'secondary'
                                : 'destructive'
                        }
                    >
                        {t('Last restore drill: :status :at', {
                            status: drill?.status ?? t('none'),
                            at: drill?.at ?? '',
                        })}
                    </Badge>
                </section>

                {hotels.map(({ id, name, metrics: m }) => (
                    <section key={id} className="flex flex-col gap-3">
                        <Heading variant="small" title={name} />
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                            <Stat
                                label={t('Conversations')}
                                value={m.conversations}
                            />
                            <Stat
                                label={t('Replied within 1 minute')}
                                value={pct(m.fast_reply_rate)}
                            />
                            <Stat
                                label={t('Handled without staff')}
                                value={pct(m.without_staff_rate)}
                            />
                            <Stat
                                label={t('Booking requests')}
                                value={m.requests}
                            />
                            <Stat
                                label={t('Median time to decide (min)')}
                                value={m.median_decision_minutes ?? '—'}
                            />
                            <Stat
                                label={t('Requests that became bookings')}
                                value={pct(m.request_to_booking_rate)}
                            />
                            <Stat
                                label={t(
                                    'Drafts sent unchanged / edited / discarded',
                                )}
                                value={`${m.drafts.sent_unchanged} / ${m.drafts.edited} / ${m.drafts.discarded}`}
                            />
                            <Stat
                                label={t('Safety blocks')}
                                value={m.safety_blocks}
                            />
                            <Stat
                                label={t('AI cost (US$)')}
                                value={m.ai_cost_usd}
                            />
                            <Stat
                                label={t('AI cost per conversation')}
                                value={m.ai_cost_per_conversation_usd ?? '—'}
                            />
                            <Stat
                                label={t('AI cost per booking')}
                                value={m.ai_cost_per_booking_usd ?? '—'}
                            />
                            <Stat
                                label={t('WhatsApp replies / templates')}
                                value={`${m.whatsapp.service} / ${m.whatsapp.templates}`}
                            />
                        </div>
                        {Object.keys(m.handovers).length > 0 && (
                            <p className="text-sm text-muted-foreground">
                                {t('Passed to staff:')}{' '}
                                {Object.entries(m.handovers)
                                    .map(
                                        ([reason, count]) =>
                                            `${reason} ${count}`,
                                    )
                                    .join(' · ')}
                            </p>
                        )}
                    </section>
                ))}
            </div>
        </>
    );
}

Pilot.layout = {
    breadcrumbs: [
        { title: 'Pilot dashboard', href: PilotDashboardController() },
    ],
};
