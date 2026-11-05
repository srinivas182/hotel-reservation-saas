import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Circle, XCircle } from 'lucide-react';
import AssistantSettingsController from '@/actions/App/Domains/Assistant/Http/Controllers/AssistantSettingsController';
import GoLiveController from '@/actions/App/Domains/GoLive/Http/Controllers/GoLiveController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { t } from '@/lib/i18n';

type Check = {
    key: string;
    label: string;
    required: boolean;
    ok: boolean;
    detail: string | null;
    fix_url: string | null;
};

type Props = {
    checks: Check[];
    ready: boolean;
    mode: string;
    mode_label: string;
    override: { at: string; reason: string | null } | null;
    drafts: {
        handled: number;
        sent_unchanged: number;
        edited: number;
        discarded: number;
        unchanged_rate: number | null;
    };
};

function Row({ check }: { check: Check }) {
    const Icon = check.ok ? CheckCircle2 : check.required ? XCircle : Circle;

    return (
        <li className="flex items-start gap-3 px-4 py-3 text-sm">
            <Icon
                className={
                    check.ok
                        ? 'mt-0.5 size-4 text-emerald-600'
                        : check.required
                          ? 'mt-0.5 size-4 text-destructive'
                          : 'mt-0.5 size-4 text-muted-foreground'
                }
            />
            <div className="min-w-0 flex-1">
                <div className="font-medium">{check.label}</div>
                {check.detail && (
                    <div className="text-muted-foreground">{check.detail}</div>
                )}
            </div>
            {!check.ok && check.fix_url && (
                <Link href={check.fix_url} className="text-sm underline">
                    {t('Fix')}
                </Link>
            )}
        </li>
    );
}

export default function GoLive({
    checks,
    ready,
    mode_label,
    override,
    drafts,
}: Props) {
    const required = checks.filter((c) => c.required);
    const recommended = checks.filter((c) => !c.required);

    return (
        <>
            <Head title={t('Go-live')} />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Heading
                    title={t('Go-live')}
                    description={t(
                        'Everything that must be ready before the assistant answers your guests.',
                    )}
                />

                <div className="flex flex-wrap items-center gap-2 rounded-xl border p-4 text-sm">
                    <Badge variant={ready ? 'default' : 'destructive'}>
                        {ready ? t('Ready') : t('Not ready yet')}
                    </Badge>
                    <span>
                        {t('Assistant:')} {mode_label}
                    </span>
                    <Link
                        href={AssistantSettingsController.show()}
                        className="ml-auto underline"
                    >
                        {t('Change the assistant mode')}
                    </Link>
                </div>

                {override && (
                    <p className="rounded-lg border border-amber-400 px-3 py-2 text-sm">
                        {t(
                            'The platform team allowed going live on :at: :reason',
                            { at: override.at, reason: override.reason ?? '' },
                        )}
                    </p>
                )}

                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Required')} />
                    <ul className="divide-y rounded-xl border">
                        {required.map((check) => (
                            <Row key={check.key} check={check} />
                        ))}
                    </ul>
                </section>

                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Recommended')} />
                    <ul className="divide-y rounded-xl border">
                        {recommended.map((check) => (
                            <Row key={check.key} check={check} />
                        ))}
                    </ul>
                </section>

                <section className="flex flex-col gap-2 rounded-xl border p-4 text-sm">
                    <Heading
                        variant="small"
                        title={t('Supervised mode results')}
                        description={t(
                            'Switch to automatic when most drafts are sent unchanged.',
                        )}
                    />
                    {drafts.handled === 0 ? (
                        <p className="text-muted-foreground">
                            {t('No drafts handled yet.')}
                        </p>
                    ) : (
                        <p>
                            {t(
                                ':rate% of :total drafts sent unchanged · :edited edited · :discarded discarded',
                                {
                                    rate: drafts.unchanged_rate ?? 0,
                                    total: drafts.handled,
                                    edited: drafts.edited,
                                    discarded: drafts.discarded,
                                },
                            )}
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}

GoLive.layout = {
    breadcrumbs: [{ title: 'Go-live', href: GoLiveController() }],
};
