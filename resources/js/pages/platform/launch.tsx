import { Head, useForm } from '@inertiajs/react';
import { Circle, CircleCheck, CircleMinus } from 'lucide-react';
import { useState } from 'react';
import LaunchController from '@/actions/App/Domains/Launch/Http/Controllers/LaunchController';
import {
    ChoiceCards,
    Field,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    Tabs,
    useTabs,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Item = {
    id: number;
    key: string;
    area: string;
    title: string;
    required: boolean;
    owner_role: string;
    status: string;
    proof: string | null;
    done_by: string | null;
    done_at: string | null;
    due_on: string | null;
    automatic: boolean;
    can_edit: boolean;
};
type Watch = {
    hours: string[];
    series: Record<string, number[]>;
    totals: Record<string, number>;
};
type Props = {
    score: { done: number; required: number; score: number; ready: boolean };
    version: string;
    items: Item[];
    watch: Watch;
};

const areaLabel = (a: string) =>
    ({
        legal: t('Legal'),
        payments: t('Payments'),
        whatsapp: t('WhatsApp and assistant'),
        infrastructure: t('Servers and backups'),
        security: t('Security'),
        support: t('Support'),
        content: t('Website and content'),
        pilot: t('Pilot'),
    })[a] ?? a;
const roleLabel = (r: string) =>
    ({
        super_admin: t('Super admin'),
        operations: t('Operations'),
        finance: t('Finance'),
        sales: t('Sales'),
    })[r] ?? r;
const seriesLabel = (k: string) =>
    ({
        signups: t('Sign-ups'),
        hotels: t('New hotels'),
        messages_in: t('Guest messages'),
        send_failures: t('Failed sends'),
        failed_jobs: t('Failed jobs'),
        support: t('Support requests'),
        payments: t('Payments'),
    })[k] ?? k;

function ItemForm({ item, onDone }: { item: Item; onDone: () => void }) {
    const form = useForm({
        status: item.status,
        proof: item.proof ?? '',
        due_on: item.due_on ?? '',
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    due_on: d.due_on || null,
                }));
                form.put(LaunchController.update.url(item.id), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="grid gap-4"
        >
            <ChoiceCards
                label={t('Status')}
                value={form.data.status}
                onChange={(v) => form.setData('status', v)}
                options={[
                    { value: 'open', label: t('To do') },
                    { value: 'done', label: t('Done') },
                    { value: 'not_needed', label: t('Not needed') },
                ]}
            />
            <Field label={t('Proof (link or note)')} error={form.errors.proof}>
                <textarea
                    className="min-h-20"
                    value={form.data.proof}
                    onChange={(e) => form.setData('proof', e.target.value)}
                />
            </Field>
            <Field label={t('Due date')}>
                <Input
                    type="date"
                    value={form.data.due_on}
                    onChange={(e) => form.setData('due_on', e.target.value)}
                />
            </Field>
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button disabled={form.processing}>{t('Save')}</Button>
            </div>
        </form>
    );
}

function StatusIcon({ status }: { status: string }) {
    if (status === 'done') {
        return (
            <CircleCheck className="size-5 shrink-0 text-emerald-700 dark:text-emerald-400" />
        );
    }

    if (status === 'not_needed') {
        return (
            <CircleMinus className="size-5 shrink-0 text-muted-foreground" />
        );
    }

    return <Circle className="size-5 shrink-0 text-muted-foreground/60" />;
}

function Spark({ values }: { values: number[] }) {
    const max = Math.max(1, ...values);
    const last = values.slice(-72);

    return (
        <div className="flex h-10 items-end gap-px" aria-hidden="true">
            {last.map((v, i) => (
                <span
                    key={i}
                    className="w-1 rounded-t bg-brand/70"
                    style={{ height: `${Math.max(2, (v / max) * 100)}%` }}
                />
            ))}
        </div>
    );
}

export default function Launch({ score, version, items, watch }: Props) {
    const areas = [...new Set(items.map((i) => i.area))];
    const tabs = [
        { key: 'checklist', label: t('Checklist'), count: items.length },
        { key: 'watch', label: t('Launch-week watch') },
    ];
    const [tab, setTab] = useTabs(tabs);
    const [editing, setEditing] = useState<Item | null>(null);

    return (
        <>
            <Head title={t('Launch')} />
            <PageBody>
                <PageHeader
                    title={t('Launch')}
                    description={t(
                        'Everything needed before version 1.0.0, and the launch-week watch.',
                    )}
                />
                <section
                    className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-[auto_1fr] sm:items-center sm:gap-6"
                    aria-live="polite"
                >
                    <div
                        className="relative size-24 shrink-0 rounded-full"
                        style={{
                            background: `conic-gradient(var(--color-brand) ${score.score * 3.6}deg, var(--color-muted) 0deg)`,
                        }}
                        role="progressbar"
                        aria-valuenow={score.score}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label={t('Launch readiness')}
                    >
                        <div className="absolute inset-2 flex items-center justify-center rounded-full bg-card font-display text-2xl font-extrabold">
                            {score.score}%
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">
                                {t(':done of :required required items done', {
                                    done: score.done,
                                    required: score.required,
                                })}
                            </span>
                            <Badge
                                variant={score.ready ? 'default' : 'outline'}
                            >
                                {score.ready
                                    ? t('Ready for 1.0.0')
                                    : t('Not ready for 1.0.0')}
                            </Badge>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Releasing 1.0.0 to production runs `php artisan launch:check` and stops while a required item is open.',
                            )}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {t('Current version :version', { version })}
                        </p>
                    </div>
                </section>
                <Tabs tabs={tabs} active={tab} onSelect={setTab} />

                {tab === 'checklist' &&
                    areas.map((area) => {
                        const list = items.filter((i) => i.area === area);
                        const done = list.filter(
                            (i) => i.status !== 'open',
                        ).length;

                        return (
                            <Section
                                key={area}
                                flush
                                title={areaLabel(area)}
                                actions={
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {done} / {list.length}
                                    </span>
                                }
                            >
                                <ul className="divide-y">
                                    {list.map((item) => (
                                        <li key={item.id}>
                                            <button
                                                type="button"
                                                disabled={!item.can_edit}
                                                onClick={() => setEditing(item)}
                                                className="flex w-full flex-wrap items-center gap-3 px-5 py-3 text-left transition-colors enabled:hover:bg-muted/40 disabled:cursor-default"
                                            >
                                                <StatusIcon
                                                    status={item.status}
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span
                                                        className={cn(
                                                            'block text-sm',
                                                            item.status ===
                                                                'open'
                                                                ? 'font-medium'
                                                                : 'text-muted-foreground',
                                                        )}
                                                    >
                                                        {item.title}
                                                    </span>
                                                    {item.proof && (
                                                        <span className="block truncate text-xs text-muted-foreground">
                                                            {item.proof}
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="flex flex-wrap items-center gap-1">
                                                    {item.required ? (
                                                        <Badge variant="outline">
                                                            {t('Required')}
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="secondary">
                                                            {t('Optional')}
                                                        </Badge>
                                                    )}
                                                    {item.automatic && (
                                                        <Badge variant="secondary">
                                                            {t(
                                                                'Checked automatically',
                                                            )}
                                                        </Badge>
                                                    )}
                                                </span>
                                                <span className="w-full text-xs text-muted-foreground sm:w-40 sm:text-right">
                                                    {roleLabel(item.owner_role)}
                                                    {item.due_on
                                                        ? ` · ${t('due :date', { date: item.due_on })}`
                                                        : ''}
                                                    {item.done_by
                                                        ? ` · ${item.done_by}`
                                                        : ''}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        );
                    })}

                {tab === 'watch' && (
                    <Section
                        title={t('Launch-week watch (last 7 days, by hour)')}
                    >
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            {Object.entries(watch.series).map(
                                ([key, values]) => (
                                    <div
                                        key={key}
                                        className="flex flex-col gap-1 rounded-lg border p-3"
                                    >
                                        <span className="text-xs text-muted-foreground">
                                            {seriesLabel(key)}
                                        </span>
                                        <span className="font-display text-2xl font-extrabold tabular-nums">
                                            {watch.totals[key]}
                                        </span>
                                        <Spark values={values} />
                                        <span className="text-[11px] text-muted-foreground">
                                            {t('Last hour: :count', {
                                                count:
                                                    values[values.length - 1] ??
                                                    0,
                                            })}
                                        </span>
                                    </div>
                                ),
                            )}
                        </div>
                    </Section>
                )}
            </PageBody>
            <SidePanel
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={editing?.title ?? ''}
                description={
                    editing
                        ? `${areaLabel(editing.area)} · ${roleLabel(editing.owner_role)}`
                        : undefined
                }
            >
                {editing && (
                    <ItemForm
                        key={editing.id}
                        item={editing}
                        onDone={() => setEditing(null)}
                    />
                )}
            </SidePanel>
        </>
    );
}

Launch.layout = {
    breadcrumbs: [{ title: 'Launch', href: LaunchController.index() }],
};
