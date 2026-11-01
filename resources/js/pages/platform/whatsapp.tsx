import { Head } from '@inertiajs/react';
import WhatsAppPlatformController from '@/actions/App/Domains/Platform/Http/Controllers/WhatsAppPlatformController';
import Heading from '@/components/heading';
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
        <div className="flex items-center justify-between gap-4 py-1">
            <span>{label}</span>
            <span className="flex items-center gap-2">
                {value && <code className="text-xs">{value}</code>}
                <Badge variant={ok ? 'default' : 'destructive'}>
                    {ok ? t('Set') : t('Missing')}
                </Badge>
            </span>
        </div>
    );
}

export default function PlatformWhatsApp({
    config,
    lastWebhookAt,
    failedEvents,
    accounts,
}: Props) {
    return (
        <>
            <Head title="WhatsApp" />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title="WhatsApp"
                    description={t(
                        'Meta app setup and every connected number.',
                    )}
                />

                <section className="rounded-xl border p-4 text-sm">
                    <Heading variant="small" title={t('Meta setup')} />
                    <div className="mt-2 divide-y">
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
                    </div>
                    <p className="mt-3">
                        {t('Webhook address for Meta:')}{' '}
                        <code className="text-xs">{config.webhook_url}</code>
                    </p>
                    <p className="text-muted-foreground">
                        {t('Graph API version:')} {config.graph_version} ·{' '}
                        {t('Last webhook received:')}{' '}
                        {lastWebhookAt ?? t('never')}
                    </p>
                    {failedEvents > 0 && (
                        <p className="text-destructive">
                            {t(
                                ':count webhook event(s) failed after retries.',
                                { count: failedEvents },
                            )}
                        </p>
                    )}
                </section>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    {t('Hotel')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Number')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Status')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Quality rating')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        {t(
                                            'No hotel has connected WhatsApp yet.',
                                        )}
                                    </td>
                                </tr>
                            )}
                            {accounts.map((account, index) => (
                                <tr key={index} className="border-t">
                                    <td className="px-4 py-3">
                                        {account.hotel}
                                    </td>
                                    <td className="px-4 py-3">
                                        {account.display_phone}
                                        <div className="text-xs text-muted-foreground">
                                            {account.verified_name}
                                            {account.coexistence &&
                                                ` · ${t('also in the Business app')}`}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge
                                            variant={
                                                account.status === 'connected'
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                        >
                                            {account.status_label}
                                        </Badge>
                                        {account.last_error && (
                                            <div className="text-xs text-destructive">
                                                {account.last_error}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {account.quality_rating ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

PlatformWhatsApp.layout = {
    breadcrumbs: [{ title: 'WhatsApp', href: WhatsAppPlatformController() }],
};
