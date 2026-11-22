import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { t } from '@/lib/i18n';

type Room = { state: 'free' | 'occupied' | 'arriving' | 'out_of_service' };

/**
 * Sprint I: the top of Today. A greeting in the hotel's time of day, the
 * date, a one-line summary and (in full mode) four figures with tonight's
 * occupancy. Everything comes from what Today already loads: no extra query.
 */
export function TodayHero({
    dateLabel,
    board,
    arrivals,
    confirmed,
    departures,
    inHouse,
    requests,
    showFigures,
    action,
}: {
    dateLabel: string;
    board: Room[];
    arrivals: number;
    confirmed: number;
    departures: number;
    inHouse: number;
    requests: number | null;
    showFigures: boolean;
    action?: ReactNode;
}) {
    const { auth, tenancy } = usePage().props as unknown as {
        auth: { user: { first_name?: string | null } };
        tenancy: { current: { timezone: string } | null };
    };
    const hour = Number(
        new Intl.DateTimeFormat('en-GB', {
            hour: 'numeric',
            hourCycle: 'h23',
            timeZone: tenancy.current?.timezone ?? undefined,
        }).format(new Date()),
    );
    const greeting =
        hour < 12
            ? t('Good morning')
            : hour < 18
              ? t('Good afternoon')
              : t('Good evening');
    const name = auth.user.first_name?.trim();

    const sellable = board.filter((r) => r.state !== 'out_of_service').length;
    const taken = board.filter(
        (r) => r.state === 'occupied' || r.state === 'arriving',
    ).length;
    const occupancy = sellable > 0 ? Math.round((taken / sellable) * 100) : 0;

    const summary = [
        t(':count arrivals', { count: arrivals }),
        t(':count departures', { count: departures }),
        ...(requests !== null && requests > 0
            ? [t(':count requests waiting for you', { count: requests })]
            : []),
    ].join(' · ');

    return (
        <header className="flex flex-col gap-5">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    {/* The page is still called "Today" for screen readers and the tests. */}
                    <h1 className="text-[11px] font-semibold tracking-[0.18em] text-brand-ink uppercase">
                        <span>{t('Today')}</span>
                        <span aria-hidden="true"> · </span>
                        <span className="sr-only">, </span>
                        <span>{dateLabel}</span>
                    </h1>
                    <p className="mt-2 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">
                        {name ? `${greeting}, ${name}` : greeting}
                    </p>
                    <p className="mt-1.5 text-sm text-muted-foreground">
                        {summary}
                    </p>
                </div>
                {action}
            </div>

            {showFigures && (
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div className="rounded-2xl bg-sidebar p-4 text-sidebar-foreground shadow-card ring-white/10 sm:p-5 dark:ring-1">
                        <p className="text-xs text-sidebar-foreground/70">
                            {t('Occupancy tonight')}
                        </p>
                        <p className="mt-1 font-display text-3xl font-extrabold text-white tabular-nums">
                            {taken} / {sellable}
                        </p>
                        <div
                            className="mt-3 h-1.5 overflow-hidden rounded-full bg-white/15"
                            role="progressbar"
                            aria-valuenow={occupancy}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-label={t('Occupancy tonight')}
                        >
                            <div
                                className="h-full rounded-full bg-sidebar-primary transition-[width] duration-700"
                                style={{ width: `${occupancy}%` }}
                            />
                        </div>
                        <p className="mt-2 text-xs font-semibold text-white/90">
                            {occupancy}%
                        </p>
                    </div>
                    <Figure
                        label={t('Arrivals')}
                        value={arrivals}
                        note={
                            arrivals > 0
                                ? t(':count confirmed on WhatsApp', {
                                      count: confirmed,
                                  })
                                : null
                        }
                        href="#arrivals"
                    />
                    <Figure
                        label={t('Departures')}
                        value={departures}
                        href="#departures"
                    />
                    <Figure
                        label={t('In house')}
                        value={inHouse}
                        href="#in-house"
                    />
                </div>
            )}
        </header>
    );
}

function Figure({
    label,
    value,
    note,
    href,
}: {
    label: string;
    value: number;
    note?: string | null;
    href: string;
}) {
    return (
        <a
            href={href}
            className="rounded-2xl border bg-card p-4 shadow-card transition-colors hover:border-brand/40 sm:p-5"
        >
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 font-display text-3xl font-extrabold tabular-nums">
                {value}
            </p>
            {note && (
                <p className="mt-2 text-xs font-semibold text-success">
                    {note}
                </p>
            )}
        </a>
    );
}
