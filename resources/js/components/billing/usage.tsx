import { Badge } from '@/components/ui/badge';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type CreditUsage = {
    kind: string;
    label: string;
    granted: number;
    used: number;
    left: number;
    percent: number;
    available: boolean;
};
export type Overview = {
    subscription: {
        plan: string | null;
        plan_id: string;
        status: string;
        interval: string;
        price: string;
        currency: string;
        period_start: string;
        period_end: string;
        trial_ends_at: string | null;
        pending_plan: string | null;
    } | null;
    credits: CreditUsage[];
    packs: { kind: string; left: number; expires: string | null }[];
    staff: { count: number; limit: number | null };
    modules: { label: string; included: boolean }[];
    whatsapp: { service: number; templates: number };
    limited: boolean;
};

export function PlanSummary({ overview }: { overview: Overview }) {
    const s = overview.subscription;

    if (!s) {
        return (
            <p className="rounded-xl border p-4 text-sm text-muted-foreground">
                {t(
                    'No plan yet: everything is included while the hotel is set up.',
                )}
            </p>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border p-4 text-sm">
            <span className="text-lg font-semibold">{s.plan}</span>
            <Badge variant={s.status === 'trialing' ? 'secondary' : 'outline'}>
                {s.status === 'trialing'
                    ? t('Trial until :date', { date: s.trial_ends_at ?? '' })
                    : t('Active')}
            </Badge>
            <span>
                {s.price} / {s.interval === 'yearly' ? t('year') : t('month')}
            </span>
            <span className="text-muted-foreground">
                {t('Current period: :from → :to', {
                    from: s.period_start,
                    to: s.period_end,
                })}
            </span>
            {s.pending_plan && (
                <Badge variant="outline">
                    {t('Changes to :plan at the end of the period', {
                        plan: s.pending_plan,
                    })}
                </Badge>
            )}
        </div>
    );
}

export function CreditBars({ overview }: { overview: Overview }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {overview.credits.map((c) => (
                <div key={c.kind} className="rounded-xl border p-4 text-sm">
                    <div className="flex items-center justify-between">
                        <span className="font-medium">{c.label}</span>
                        {!c.available && (
                            <Badge variant="destructive">{t('Used up')}</Badge>
                        )}
                    </div>
                    <div className="my-2 h-2 rounded bg-muted">
                        <div
                            className={cn(
                                'h-2 rounded',
                                c.percent >= 100
                                    ? 'bg-destructive'
                                    : c.percent >= 80
                                      ? 'bg-amber-500'
                                      : 'bg-primary',
                            )}
                            style={{ width: `${c.percent}%` }}
                        />
                    </div>
                    <span className="text-muted-foreground">
                        {t(':used used of :granted · :left left', {
                            used: c.used,
                            granted: c.granted,
                            left: c.left,
                        })}
                    </span>
                </div>
            ))}
            <div className="rounded-xl border p-4 text-sm">
                <span className="font-medium">{t('Staff accounts')}</span>
                <p className="text-muted-foreground">
                    {overview.staff.limit === null
                        ? t(':count (no limit)', {
                              count: overview.staff.count,
                          })
                        : t(':count of :limit', {
                              count: overview.staff.count,
                              limit: overview.staff.limit,
                          })}
                </p>
            </div>
            <div className="rounded-xl border p-4 text-sm">
                <span className="font-medium">{t('WhatsApp this period')}</span>
                <p className="text-muted-foreground">
                    {t(
                        ':service replies to guests · :templates template messages (billed by Meta to the hotel)',
                        {
                            service: overview.whatsapp.service,
                            templates: overview.whatsapp.templates,
                        },
                    )}
                </p>
            </div>
            <div className="rounded-xl border p-4 text-sm sm:col-span-2">
                <span className="font-medium">{t('Modules')}</span>
                <div className="mt-1 flex flex-wrap gap-2">
                    {overview.modules.map((m) => (
                        <Badge
                            key={m.label}
                            variant={m.included ? 'default' : 'outline'}
                        >
                            {m.included ? '✓ ' : '— '}
                            {m.label}
                        </Badge>
                    ))}
                </div>
            </div>
            {overview.packs.length > 0 && (
                <div className="rounded-xl border p-4 text-sm sm:col-span-2">
                    <span className="font-medium">{t('Credit packs')}</span>
                    <ul className="text-muted-foreground">
                        {overview.packs.map((p, i) => (
                            <li key={i}>
                                {t(':kind: :left left, valid until :date', {
                                    kind: p.kind,
                                    left: p.left,
                                    date: p.expires ?? '',
                                })}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
