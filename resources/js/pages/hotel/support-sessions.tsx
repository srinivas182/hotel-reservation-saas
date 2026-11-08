import { Head, router } from '@inertiajs/react';
import SupportSessionController from '@/actions/App/Domains/Platform/Http/Controllers/SupportSessionController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type Session = {
    id: string;
    by: string | null;
    reason: string;
    mode: string;
    status: string;
    requested: string | null;
    started: string | null;
    ended: string | null;
    actions: number;
};
type Props = { setting: string; sessions: Session[] };

export default function SupportSessions({ setting, sessions }: Props) {
    return (
        <>
            <Head title={t('Support access')} />
            <div className="flex max-w-4xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('Support access')}
                    description={t(
                        'When our support team helps inside your account, it appears here. Every action they take is in your history.',
                    )}
                />
                <section className="flex flex-col gap-2 rounded-xl border p-3">
                    <label className="flex items-center gap-2">
                        <input
                            type="radio"
                            name="support_access"
                            checked={setting === 'notify'}
                            onChange={() =>
                                router.put(
                                    SupportSessionController.setting.url(),
                                    { support_access: 'notify' },
                                    { preserveScroll: true },
                                )
                            }
                        />
                        {t('Allowed: tell me by email when a session starts')}
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="radio"
                            name="support_access"
                            checked={setting === 'ask_first'}
                            onChange={() =>
                                router.put(
                                    SupportSessionController.setting.url(),
                                    { support_access: 'ask_first' },
                                    { preserveScroll: true },
                                )
                            }
                        />
                        {t('Ask me first: I approve each session')}
                    </label>
                </section>
                <ul className="divide-y rounded-xl border">
                    {sessions.length === 0 && (
                        <li className="px-3 py-2 text-muted-foreground">
                            {t('No support sessions yet.')}
                        </li>
                    )}
                    {sessions.map((s) => (
                        <li
                            key={s.id}
                            className="flex flex-wrap items-center gap-2 px-3 py-2"
                        >
                            <span className="font-medium">{s.by}</span>
                            <span>{s.reason}</span>
                            <Badge
                                variant={
                                    s.mode === 'write'
                                        ? 'destructive'
                                        : 'outline'
                                }
                            >
                                {s.mode === 'write'
                                    ? t('can make changes')
                                    : t('read-only')}
                            </Badge>
                            <Badge
                                variant={
                                    s.status === 'active'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {{
                                    pending: t('Waiting for you'),
                                    active: t('In progress'),
                                    ended: t('Ended'),
                                    refused: t('Refused'),
                                }[s.status] ?? s.status}
                            </Badge>
                            <span className="text-xs text-muted-foreground">
                                {s.started ?? s.requested}{' '}
                                {s.ended ? `→ ${s.ended}` : ''} ·{' '}
                                {t(':count action(s)', { count: s.actions })}
                            </span>
                            <span className="ml-auto flex gap-1">
                                {s.status === 'pending' && (
                                    <>
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    SupportSessionController.decide.url(
                                                        s.id,
                                                    ),
                                                    { approve: true },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Approve')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    SupportSessionController.decide.url(
                                                        s.id,
                                                    ),
                                                    { approve: false },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Refuse')}
                                        </Button>
                                    </>
                                )}
                                {s.status === 'active' && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                SupportSessionController.stop.url(
                                                    s.id,
                                                ),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('End session')}
                                    </Button>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

SupportSessions.layout = {
    breadcrumbs: [
        { title: 'Support access', href: SupportSessionController.index() },
    ],
};
