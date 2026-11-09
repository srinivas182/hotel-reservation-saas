import { Head, useForm } from '@inertiajs/react';
import LaunchController from '@/actions/App/Domains/Launch/Http/Controllers/LaunchController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

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

function ItemRow({ item }: { item: Item }) {
    const form = useForm({
        status: item.status,
        proof: item.proof ?? '',
        due_on: item.due_on ?? '',
    });

    return (
        <li className="flex flex-col gap-2 px-3 py-2">
            <div className="flex flex-wrap items-center gap-2">
                <span aria-hidden="true">
                    {item.status === 'done'
                        ? '✅'
                        : item.status === 'not_needed'
                          ? '➖'
                          : '⬜'}
                </span>
                <span
                    className={
                        item.status === 'done'
                            ? 'text-muted-foreground line-through'
                            : 'font-medium'
                    }
                >
                    {item.title}
                </span>
                {item.required ? (
                    <Badge variant="outline">{t('Required')}</Badge>
                ) : (
                    <Badge variant="secondary">{t('Optional')}</Badge>
                )}
                {item.automatic && (
                    <Badge variant="secondary">
                        {t('Checked automatically')}
                    </Badge>
                )}
                <span className="ml-auto text-xs text-muted-foreground">
                    {roleLabel(item.owner_role)}{' '}
                    {item.due_on
                        ? `· ${t('due :date', { date: item.due_on })}`
                        : ''}{' '}
                    {item.done_by ? `· ${item.done_by}` : ''}
                </span>
            </div>
            {item.proof && (
                <p className="text-xs break-all text-muted-foreground">
                    {item.proof}
                </p>
            )}
            {item.can_edit && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            ...d,
                            due_on: d.due_on || null,
                        }));
                        form.put(LaunchController.update.url(item.id), {
                            preserveScroll: true,
                        });
                    }}
                    className="grid gap-2 sm:grid-cols-[150px_1fr_160px_auto]"
                >
                    <select
                        aria-label={t('Status')}
                        className="flex h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        value={form.data.status}
                        onChange={(e) => form.setData('status', e.target.value)}
                    >
                        <option value="open">{t('To do')}</option>
                        <option value="done">{t('Done')}</option>
                        <option value="not_needed">{t('Not needed')}</option>
                    </select>
                    <Input
                        aria-label={t('Proof (link or note)')}
                        placeholder={t('Proof (link or note)')}
                        value={form.data.proof}
                        onChange={(e) => form.setData('proof', e.target.value)}
                    />
                    <Input
                        aria-label={t('Due date')}
                        type="date"
                        value={form.data.due_on}
                        onChange={(e) => form.setData('due_on', e.target.value)}
                    />
                    <Button size="sm" disabled={form.processing}>
                        {t('Save')}
                    </Button>
                    <InputError message={form.errors.proof} />
                </form>
            )}
        </li>
    );
}

function Spark({ values }: { values: number[] }) {
    const max = Math.max(1, ...values);
    const last = values.slice(-72);

    return (
        <div className="flex h-10 items-end gap-px" aria-hidden="true">
            {last.map((v, i) => (
                <span
                    key={i}
                    className="w-1 rounded-t bg-primary/70"
                    style={{ height: `${Math.max(2, (v / max) * 100)}%` }}
                />
            ))}
        </div>
    );
}

export default function Launch({ score, version, items, watch }: Props) {
    const areas = [...new Set(items.map((i) => i.area))];

    return (
        <>
            <Head title={t('Launch')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('Launch')}
                    description={t(
                        'Everything needed before version 1.0.0, and the launch-week watch.',
                    )}
                />
                <section
                    className="flex flex-col gap-2 rounded-xl border p-4"
                    aria-live="polite"
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="text-3xl font-semibold">
                            {score.score}%
                        </span>
                        <span>
                            {t(':done of :required required items done', {
                                done: score.done,
                                required: score.required,
                            })}
                        </span>
                        <Badge variant={score.ready ? 'default' : 'outline'}>
                            {score.ready
                                ? t('Ready for 1.0.0')
                                : t('Not ready for 1.0.0')}
                        </Badge>
                        <span className="ml-auto text-xs text-muted-foreground">
                            {t('Current version :version', { version })}
                        </span>
                    </div>
                    <div
                        className="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-valuenow={score.score}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label={t('Launch readiness')}
                    >
                        <div
                            className="h-full bg-primary"
                            style={{ width: `${score.score}%` }}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Releasing 1.0.0 to production runs `php artisan launch:check` and stops while a required item is open.',
                        )}
                    </p>
                </section>
                {areas.map((area) => (
                    <section key={area} className="flex flex-col gap-1">
                        <Heading variant="small" title={areaLabel(area)} />
                        <ul className="divide-y rounded-xl border">
                            {items
                                .filter((i) => i.area === area)
                                .map((i) => (
                                    <ItemRow key={i.id} item={i} />
                                ))}
                        </ul>
                    </section>
                ))}
                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('Launch-week watch (last 7 days, by hour)')}
                    />
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {Object.entries(watch.series).map(([key, values]) => (
                            <div
                                key={key}
                                className="flex flex-col gap-1 rounded-xl border p-3"
                            >
                                <span className="text-xs text-muted-foreground">
                                    {seriesLabel(key)}
                                </span>
                                <span className="text-xl font-semibold">
                                    {watch.totals[key]}
                                </span>
                                <Spark values={values} />
                                <span className="text-[11px] text-muted-foreground">
                                    {t('Last hour: :count', {
                                        count: values[values.length - 1] ?? 0,
                                    })}
                                </span>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}

Launch.layout = {
    breadcrumbs: [{ title: 'Launch', href: LaunchController.index() }],
};
