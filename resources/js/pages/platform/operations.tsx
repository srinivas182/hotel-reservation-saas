import { Head, Link, router } from '@inertiajs/react';
import { CircleCheck, TriangleAlert } from 'lucide-react';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import OperationsController from '@/actions/App/Domains/Platform/Http/Controllers/OperationsController';
import {
    DataTable,
    PageBody,
    PageHeader,
    Section,
    StatTile,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { askConfirm } from '@/lib/ask';

type Props = {
    warnings?: { key: string; detail: string }[];
    problems: { key: string; detail: string }[];
    incidents: {
        id: number;
        title: string;
        opened: string;
        resolved: string | null;
    }[];
    heartbeats: { name: string; ok: boolean; detail?: string | null }[];
    waiting: number;
    failed: {
        uuid: string;
        queue: string;
        job: string;
        error: string;
        failed_at: string;
    }[];
    backups: {
        kind: string;
        status: string;
        at: string | null;
        off_site: boolean;
        size: number | null;
    }[];
    whatsapp: {
        hotel: string;
        hotel_id: string;
        phone: string | null;
        status: string;
        quality: string | null;
        limit: string | null;
        error: string | null;
        checked: string | null;
    }[];
};

const problemTitle = (key: string) =>
    ({
        queue_backlog: t('Background jobs are backing up'),
        scheduler_stopped: t('The scheduler has stopped'),
        queue_stopped: t('Background workers have stopped'),
        webhook_silence: t('No WhatsApp notifications are arriving'),
        webhook_failures: t('WhatsApp notifications are failing to process'),
        ai_failures: t('AI providers are failing'),
        whatsapp_failures: t('WhatsApp messages are failing to send'),
        backup_missing: t('No recent successful backup'),
        backup_failed: t('The last backup failed'),
        restore_drill: t('The restore drill failed or is overdue'),
        email_not_delivered: t('Emails are not being delivered'),
    })[key] ?? key;

export default function Operations({
    warnings = [],
    problems,
    incidents,
    heartbeats,
    waiting,
    failed,
    backups,
    whatsapp,
}: Props) {
    const lastBackup = backups.find((b) => b.kind !== 'restore_drill');

    return (
        <>
            <Head title={t('Operations')} />
            <PageBody>
                <PageHeader
                    title={t('Operations')}
                    description={t(
                        'Live checks, background jobs, backups and WhatsApp connections.',
                    )}
                />

                {warnings.length > 0 && (
                    <ul
                        role="status"
                        className="flex flex-col gap-1 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
                    >
                        {warnings.map((w) => (
                            <li key={w.key} className="flex items-center gap-2">
                                <TriangleAlert className="size-4 shrink-0" />
                                {w.detail}
                            </li>
                        ))}
                    </ul>
                )}
                {problems.length === 0 && warnings.length === 0 ? (
                    <div
                        role="status"
                        className="flex items-center gap-3 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
                    >
                        <CircleCheck className="size-4 shrink-0" />
                        {t('All checks pass.')}
                    </div>
                ) : problems.length === 0 ? null : (
                    <section
                        role="alert"
                        className="overflow-hidden rounded-xl border border-destructive/40 bg-card"
                    >
                        <h2 className="flex items-center gap-2 border-b border-destructive/30 bg-destructive/5 px-5 py-3 font-medium text-destructive">
                            <TriangleAlert className="size-4" />
                            {t(':count problem(s) need attention', {
                                count: problems.length,
                            })}
                        </h2>
                        <ul className="divide-y">
                            {problems.map((p) => (
                                <li
                                    key={p.key}
                                    className="grid gap-0.5 px-5 py-3"
                                >
                                    <span className="font-medium">
                                        {problemTitle(p.key)}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {p.detail}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {heartbeats.map((h) => (
                        <StatTile
                            key={h.name}
                            label={
                                h.name === 'scheduler'
                                    ? t('Scheduler')
                                    : h.name === 'queue'
                                      ? t('Background workers')
                                      : h.name
                            }
                            value={
                                <span
                                    className={
                                        h.ok
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : 'text-destructive'
                                    }
                                >
                                    {h.ok ? t('Running') : t('Stopped')}
                                </span>
                            }
                            hint={h.ok ? undefined : (h.detail ?? undefined)}
                        />
                    ))}
                    <StatTile label={t('Jobs waiting')} value={waiting} />
                    <StatTile
                        label={t('Last backup')}
                        value={
                            <span
                                className={cn(
                                    'text-base',
                                    lastBackup?.status !== 'success' &&
                                        'text-destructive',
                                )}
                            >
                                {lastBackup?.at ?? t('None')}
                            </span>
                        }
                        hint={lastBackup?.status}
                    />
                </div>

                <Section
                    flush
                    title={t('Failed jobs')}
                    description={t(
                        'Jobs that failed after their retries. Retry once the cause is fixed.',
                    )}
                >
                    <DataTable
                        rows={failed}
                        rowKey={(f) => f.uuid}
                        empty={t('No failed jobs.')}
                        columns={[
                            {
                                key: 'job',
                                header: t('Job'),
                                cell: (f) => (
                                    <div>
                                        <div className="font-mono text-xs">
                                            {f.job}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {f.queue} · {f.failed_at}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'error',
                                header: t('Error'),
                                cell: (f) => (
                                    <span
                                        className="line-clamp-2 text-xs"
                                        title={f.error}
                                    >
                                        {f.error}
                                    </span>
                                ),
                            },
                            {
                                key: 'actions',
                                header: '',
                                align: 'right',
                                cell: (f) => (
                                    <div className="flex justify-end gap-1">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    OperationsController.retry.url(
                                                        f.uuid,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Retry')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="text-destructive"
                                            onClick={async () =>
                                                (await askConfirm(
                                                    t('Discard this job?'),
                                                )) &&
                                                router.delete(
                                                    OperationsController.forget.url(
                                                        f.uuid,
                                                    ),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Discard')}
                                        </Button>
                                    </div>
                                ),
                            },
                        ]}
                    />
                </Section>

                <Section
                    flush
                    title={t('WhatsApp connections')}
                    description={t(
                        'Token, quality and sending limit of every connected number.',
                    )}
                >
                    <DataTable
                        rows={whatsapp}
                        rowKey={(w) => w.hotel_id + (w.phone ?? '')}
                        empty={t('No hotel has connected WhatsApp yet.')}
                        columns={[
                            {
                                key: 'hotel',
                                header: t('Hotel'),
                                cell: (w) => (
                                    <div>
                                        <Link
                                            href={HotelOverviewController.show(
                                                w.hotel_id,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {w.hotel}
                                        </Link>
                                        <div className="text-xs text-muted-foreground tabular-nums">
                                            {w.phone}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (w) => (
                                    <Badge
                                        variant={
                                            w.status === 'connected'
                                                ? 'default'
                                                : 'destructive'
                                        }
                                    >
                                        {w.status}
                                    </Badge>
                                ),
                            },
                            {
                                key: 'quality',
                                header: t('Quality / limit'),
                                cell: (w) =>
                                    `${w.quality ?? '—'} / ${w.limit ?? '—'}`,
                            },
                            {
                                key: 'error',
                                header: t('Last error'),
                                cell: (w) => (
                                    <span
                                        className="line-clamp-1 text-xs text-destructive"
                                        title={w.error ?? undefined}
                                    >
                                        {w.error ?? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </span>
                                ),
                            },
                            {
                                key: 'checked',
                                header: t('Token checked'),
                                cell: (w) => (
                                    <span className="text-xs text-muted-foreground">
                                        {w.checked ?? '—'}
                                    </span>
                                ),
                            },
                        ]}
                    />
                </Section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Section flush title={t('Backups')}>
                        <DataTable
                            rows={backups}
                            rowKey={(b) => `${b.kind}-${b.at ?? ''}`}
                            empty={t('No backup yet.')}
                            columns={[
                                {
                                    key: 'kind',
                                    header: t('Kind'),
                                    cell: (b) =>
                                        b.kind === 'restore_drill'
                                            ? t('Restore drill')
                                            : t('Backup'),
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (b) => (
                                        <Badge
                                            variant={
                                                b.status === 'success'
                                                    ? 'outline'
                                                    : 'destructive'
                                            }
                                        >
                                            {b.status}
                                        </Badge>
                                    ),
                                },
                                {
                                    key: 'at',
                                    header: t('When'),
                                    cell: (b) => (
                                        <span className="text-xs text-muted-foreground">
                                            {b.at}
                                            {b.off_site &&
                                                ` · ${t('off-site')}`}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                    <Section flush title={t('Recent incidents')}>
                        <DataTable
                            rows={incidents}
                            rowKey={(i) => i.id}
                            empty={t('No incidents.')}
                            columns={[
                                {
                                    key: 'title',
                                    header: t('Incident'),
                                    cell: (i) => (
                                        <div>
                                            <div>{i.title}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {i.opened}
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (i) =>
                                        i.resolved ? (
                                            <Badge variant="outline">
                                                {t('Resolved')}
                                            </Badge>
                                        ) : (
                                            <Badge variant="destructive">
                                                {t('Open')}
                                            </Badge>
                                        ),
                                },
                            ]}
                        />
                    </Section>
                </div>
            </PageBody>
        </>
    );
}

Operations.layout = {
    breadcrumbs: [{ title: 'Operations', href: OperationsController.index() }],
};
