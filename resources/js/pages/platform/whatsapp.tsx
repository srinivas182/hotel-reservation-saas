import { Head } from '@inertiajs/react';
import { CircleAlert, CircleCheck } from 'lucide-react';
import WhatsAppPlatformController from '@/actions/App/Domains/Platform/Http/Controllers/WhatsAppPlatformController';
import {
    DataTable,
    PageBody,
    PageHeader,
    Section,
    StatTile,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { t } from '@/lib/i18n';

type Props = {
    config: {
        app_id: string | null;
        config_id: string | null;
        graph_version: string;
        app_secret_set: boolean;
        verify_token_set: boolean;
        webhook_url: string;
    };
    lastWebhookAt: string | null;
    failedEvents: number;
    accounts: {
        hotel: string;
        display_phone: string | null;
        verified_name: string | null;
        status: string;
        status_label: string;
        quality_rating: string | null;
        coexistence: boolean;
        last_error: string | null;
    }[];
};

function Check({
    ok,
    label,
    value,
}: {
    ok: boolean;
    label: string;
    value?: string | null;
}) {
    return (
        <li className="flex items-center justify-between gap-4 px-5 py-3">
            <span className="flex items-center gap-2">
                {ok ? (
                    <CircleCheck className="size-4 text-emerald-700 dark:text-emerald-400" />
                ) : (
                    <CircleAlert className="size-4 text-destructive" />
                )}
                {label}
            </span>
            <span className="flex min-w-0 items-center gap-2">
                {value && (
                    <code className="truncate text-xs text-muted-foreground">
                        {value}
                    </code>
                )}
                <Badge variant={ok ? 'outline' : 'destructive'}>
                    {ok ? t('Set') : t('Missing')}
                </Badge>
            </span>
        </li>
    );
}

export default function PlatformWhatsApp({
    config,
    lastWebhookAt,
    failedEvents,
    accounts,
}: Props) {
    const connected = accounts.filter((a) => a.status === 'connected').length;
    const ready =
        Boolean(config.app_id) &&
        config.app_secret_set &&
        Boolean(config.config_id) &&
        config.verify_token_set;

    return (
        <>
            <Head title="WhatsApp" />
            <PageBody>
                <PageHeader
                    title="WhatsApp"
                    description={t(
                        'Meta app setup and every connected number.',
                    )}
                />

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatTile
                        label={t('Meta setup')}
                        value={
                            <span
                                className={
                                    ready
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-destructive'
                                }
                            >
                                {ready ? t('Complete') : t('Incomplete')}
                            </span>
                        }
                    />
                    <StatTile
                        label={t('Connected numbers')}
                        value={connected}
                        hint={t(':count in total', { count: accounts.length })}
                    />
                    <StatTile
                        label={t('Last webhook received')}
                        value={
                            <span className="text-base">
                                {lastWebhookAt ?? t('never')}
                            </span>
                        }
                    />
                    <StatTile
                        label={t('Failed webhook events')}
                        value={
                            <span
                                className={
                                    failedEvents > 0 ? 'text-destructive' : ''
                                }
                            >
                                {failedEvents}
                            </span>
                        }
                    />
                </div>

                <Section
                    flush
                    title={t('Connected numbers')}
                    description={t(
                        'Every hotel number linked to the Meta app.',
                    )}
                >
                    <DataTable
                        rows={accounts}
                        rowKey={(a) => `${a.hotel}-${a.display_phone ?? ''}`}
                        empty={t('No hotel has connected WhatsApp yet.')}
                        columns={[
                            {
                                key: 'hotel',
                                header: t('Hotel'),
                                cell: (a) => (
                                    <span className="font-medium">
                                        {a.hotel}
                                    </span>
                                ),
                            },
                            {
                                key: 'number',
                                header: t('Number'),
                                cell: (a) => (
                                    <div>
                                        <div className="tabular-nums">
                                            {a.display_phone ?? '—'}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {a.verified_name}
                                            {a.coexistence &&
                                                ` · ${t('also in the Business app')}`}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (a) => (
                                    <div>
                                        <Badge
                                            variant={
                                                a.status === 'connected'
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                        >
                                            {a.status_label}
                                        </Badge>
                                        {a.last_error && (
                                            <div className="mt-1 text-xs text-destructive">
                                                {a.last_error}
                                            </div>
                                        )}
                                    </div>
                                ),
                            },
                            {
                                key: 'quality',
                                header: t('Quality rating'),
                                cell: (a) => a.quality_rating ?? '—',
                            },
                        ]}
                    />
                </Section>

                <Section
                    flush
                    title={t('Meta setup')}
                    description={t(
                        'Set in the server settings. Every line must be set before hotels can connect.',
                    )}
                >
                    <ul className="divide-y">
                        <Check
                            ok={Boolean(config.app_id)}
                            label={t('App ID')}
                            value={config.app_id}
                        />
                        <Check
                            ok={config.app_secret_set}
                            label={t('App secret')}
                        />
                        <Check
                            ok={Boolean(config.config_id)}
                            label={t('Embedded Signup configuration ID')}
                            value={config.config_id}
                        />
                        <Check
                            ok={config.verify_token_set}
                            label={t('Webhook verify token')}
                        />
                    </ul>
                    <dl className="grid gap-3 border-t bg-muted/30 px-5 py-4 text-sm sm:grid-cols-[12rem_1fr]">
                        <dt className="text-muted-foreground">
                            {t('Webhook address for Meta:')}
                        </dt>
                        <dd>
                            <code className="text-xs break-all">
                                {config.webhook_url}
                            </code>
                        </dd>
                        <dt className="text-muted-foreground">
                            {t('Graph API version:')}
                        </dt>
                        <dd>{config.graph_version}</dd>
                    </dl>
                </Section>
            </PageBody>
        </>
    );
}

PlatformWhatsApp.layout = {
    breadcrumbs: [{ title: 'WhatsApp', href: WhatsAppPlatformController() }],
};
