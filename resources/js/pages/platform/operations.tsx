import { Head, Link, router } from '@inertiajs/react';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import OperationsController from '@/actions/App/Domains/Platform/Http/Controllers/OperationsController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type Props = {
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

export default function Operations({
    problems,
    incidents,
    heartbeats,
    waiting,
    failed,
    backups,
    whatsapp,
}: Props) {
    return (
        <>
            <Head title={t('Operations')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('Operations')}
                    description={t(
                        'Live checks, background jobs, backups and WhatsApp connections.',
                    )}
                />

                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Checks')} />
                    {problems.length === 0 ? (
                        <p className="text-emerald-700 dark:text-emerald-400">
                            {t('All checks pass.')}
                        </p>
                    ) : (
                        problems.map((p) => (
                            <p
                                key={p.key}
                                className="rounded-lg border border-destructive px-3 py-2 text-destructive"
                            >
                                <span className="font-mono text-xs">
                                    {p.key}
                                </span>{' '}
                                · {p.detail}
                            </p>
                        ))
                    )}
                    <div className="flex flex-wrap gap-2">
                        {heartbeats.map((h) => (
                            <Badge
                                key={h.name}
                                variant={h.ok ? 'secondary' : 'destructive'}
                            >
                                {h.name}:{' '}
                                {h.ok
                                    ? t('running')
                                    : (h.detail ?? t('stopped'))}
                            </Badge>
                        ))}
                        <Badge variant="outline">
                            {t(':count jobs waiting', { count: waiting })}
                        </Badge>
                    </div>
                </section>

                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Failed jobs')} />
                    {failed.length === 0 && (
                        <p className="text-muted-foreground">
                            {t('No failed jobs.')}
                        </p>
                    )}
                    <ul className="divide-y rounded-xl border">
                        {failed.map((f) => (
                            <li
                                key={f.uuid}
                                className="flex flex-wrap items-center gap-2 px-3 py-2"
                            >
                                <span className="font-mono text-xs">
                                    {f.job}
                                </span>
                                <span className="text-muted-foreground">
                                    {f.queue}
                                </span>
                                <span className="flex-1 text-xs">
                                    {f.error}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {f.failed_at}
                                </span>
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
                                    onClick={() =>
                                        window.confirm(
                                            t('Discard this job?'),
                                        ) &&
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
                            </li>
                        ))}
                    </ul>
                </section>

                <div className="grid gap-4 md:grid-cols-2">
                    <section className="flex flex-col gap-2">
                        <Heading variant="small" title={t('Backups')} />
                        <ul className="divide-y rounded-xl border">
                            {backups.map((b, i) => (
                                <li key={i} className="flex gap-2 px-3 py-1.5">
                                    <span>
                                        {b.kind === 'restore_drill'
                                            ? t('Restore drill')
                                            : t('Backup')}
                                    </span>
                                    <Badge
                                        variant={
                                            b.status === 'success'
                                                ? 'secondary'
                                                : 'destructive'
                                        }
                                    >
                                        {b.status}
                                    </Badge>
                                    <span className="text-muted-foreground">
                                        {b.at}
                                    </span>
                                    {b.off_site && (
                                        <span className="text-xs text-muted-foreground">
                                            {t('off-site')}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                    <section className="flex flex-col gap-2">
                        <Heading
                            variant="small"
                            title={t('Recent incidents')}
                        />
                        <ul className="divide-y rounded-xl border">
                            {incidents.map((i) => (
                                <li
                                    key={i.id}
                                    className="flex gap-2 px-3 py-1.5"
                                >
                                    <span className="flex-1">{i.title}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {i.opened}
                                    </span>
                                    {i.resolved ? (
                                        <Badge variant="secondary">
                                            {t('Resolved')}
                                        </Badge>
                                    ) : (
                                        <Badge variant="destructive">
                                            {t('Open')}
                                        </Badge>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>

                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('WhatsApp connections')}
                    />
                    <div
                        tabIndex={0}
                        className="overflow-x-auto rounded-xl border"
                    >
                        <table className="w-full min-w-max text-left">
                            <thead className="text-xs text-muted-foreground">
                                <tr>
                                    <th className="p-2">{t('Hotel')}</th>
                                    <th className="p-2">{t('Number')}</th>
                                    <th className="p-2">{t('Status')}</th>
                                    <th className="p-2">
                                        {t('Quality / limit')}
                                    </th>
                                    <th className="p-2">{t('Last error')}</th>
                                    <th className="p-2">
                                        {t('Token checked')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {whatsapp.map((w) => (
                                    <tr
                                        key={w.hotel_id + (w.phone ?? '')}
                                        className="border-t"
                                    >
                                        <td className="p-2">
                                            <Link
                                                href={HotelOverviewController.show(
                                                    w.hotel_id,
                                                )}
                                                className="underline"
                                            >
                                                {w.hotel}
                                            </Link>
                                        </td>
                                        <td className="p-2">{w.phone}</td>
                                        <td className="p-2">
                                            <Badge
                                                variant={
                                                    w.status === 'connected'
                                                        ? 'secondary'
                                                        : 'destructive'
                                                }
                                            >
                                                {w.status}
                                            </Badge>
                                        </td>
                                        <td className="p-2">
                                            {w.quality ?? '—'} /{' '}
                                            {w.limit ?? '—'}
                                        </td>
                                        <td className="max-w-80 truncate p-2 text-xs">
                                            {w.error}
                                        </td>
                                        <td className="p-2 text-xs">
                                            {w.checked}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}

Operations.layout = {
    breadcrumbs: [{ title: 'Operations', href: OperationsController.index() }],
};
