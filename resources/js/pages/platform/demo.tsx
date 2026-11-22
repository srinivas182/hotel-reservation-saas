import { Head, router } from '@inertiajs/react';
import { ExternalLink, RotateCcw } from 'lucide-react';
import PlatformDemoController from '@/actions/App/Domains/Demo/Http/Controllers/PlatformDemoController';
import {
    DataTable,
    EmptyState,
    PageBody,
    PageHeader,
    Section,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type Props = {
    demo: {
        id: string;
        name: string;
        login: string | null;
        link: string;
    } | null;
    privateDemos: {
        id: string;
        name: string;
        expires: string | null;
        lead: string | null;
        opened: string | null;
    }[];
    credentials: { email: string; password: string } | null;
    demoLink: string | null;
};

export default function PlatformDemo({
    demo,
    privateDemos,
    credentials,
    demoLink,
}: Props) {
    return (
        <>
            <Head title={t('Demo hotel')} />
            <PageBody>
                <PageHeader
                    title={t('Demo hotel')}
                    description={t(
                        'A realistic hotel for sales demos. The assistant answers for real; nothing is sent on WhatsApp. Reset every night at 03:15.',
                    )}
                    actions={
                        !demo && (
                            <Button
                                onClick={() =>
                                    router.post(
                                        PlatformDemoController.create.url(),
                                    )
                                }
                            >
                                {t('Create the demo hotel')}
                            </Button>
                        )
                    }
                />
                {credentials && (
                    <div
                        role="status"
                        className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                    >
                        {t('Login: :email · password: :password (shown once)', {
                            email: credentials.email,
                            password: credentials.password,
                        })}
                    </div>
                )}
                {demoLink && (
                    <div
                        role="status"
                        className="rounded-xl border border-brand/30 bg-brand/5 px-4 py-3 text-sm break-all"
                    >
                        {t('Private demo link (48 h):')} {demoLink}
                    </div>
                )}
                {demo ? (
                    <Section
                        title={demo.name}
                        description={t('Login: :email', {
                            email: demo.login ?? '',
                        })}
                        actions={
                            <>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            PlatformDemoController.password.url(),
                                        )
                                    }
                                >
                                    {t('New password')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            PlatformDemoController.reset.url(),
                                        )
                                    }
                                >
                                    <RotateCcw /> {t('Reset now')}
                                </Button>
                            </>
                        }
                    >
                        <div className="flex flex-wrap items-center gap-3">
                            <Button asChild>
                                <a
                                    href={demo.link}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink />{' '}
                                    {t('Open the demo (link valid 1 hour)')}
                                </a>
                            </Button>
                            <span className="text-xs text-muted-foreground">
                                {t(
                                    'Open it in a private window to stay signed in here.',
                                )}
                            </span>
                        </div>
                    </Section>
                ) : (
                    <EmptyState text={t('No demo hotel yet.')} />
                )}
                <Section
                    flush
                    title={t('Private demos for prospects')}
                    description={t('Sent from Leads; deleted after 48 hours.')}
                >
                    <DataTable
                        rows={privateDemos}
                        rowKey={(d) => d.id}
                        empty={t('None right now.')}
                        columns={[
                            {
                                key: 'name',
                                header: t('Prospect'),
                                cell: (d) => (
                                    <div>
                                        <div className="font-medium">
                                            {d.name}
                                        </div>
                                        {d.lead && (
                                            <div className="text-xs text-muted-foreground">
                                                {d.lead}
                                            </div>
                                        )}
                                    </div>
                                ),
                            },
                            {
                                key: 'opened',
                                header: t('Status'),
                                cell: (d) =>
                                    d.opened ? (
                                        <Badge>
                                            {t('Opened :date', {
                                                date: d.opened,
                                            })}
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline">
                                            {t('Not opened yet')}
                                        </Badge>
                                    ),
                            },
                            {
                                key: 'expires',
                                header: t('Deleted on'),
                                cell: (d) => (
                                    <span className="text-xs text-muted-foreground">
                                        {d.expires ?? '—'}
                                    </span>
                                ),
                            },
                        ]}
                    />
                </Section>
            </PageBody>
        </>
    );
}

PlatformDemo.layout = {
    breadcrumbs: [
        { title: 'Demo hotel', href: PlatformDemoController.index() },
    ],
};
