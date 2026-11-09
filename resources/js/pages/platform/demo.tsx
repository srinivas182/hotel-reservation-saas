import { Head, router } from '@inertiajs/react';
import PlatformDemoController from '@/actions/App/Domains/Demo/Http/Controllers/PlatformDemoController';
import Heading from '@/components/heading';
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
            <div className="flex max-w-4xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('Demo hotel')}
                    description={t(
                        'A realistic hotel for sales demos. The assistant answers for real; nothing is sent on WhatsApp. Reset every night at 03:15.',
                    )}
                />
                {credentials && (
                    <p
                        role="status"
                        className="rounded-lg border border-amber-600 px-3 py-2"
                    >
                        {t('Login: :email · password: :password (shown once)', {
                            email: credentials.email,
                            password: credentials.password,
                        })}
                    </p>
                )}
                {demoLink && (
                    <p
                        role="status"
                        className="rounded-lg border px-3 py-2 break-all"
                    >
                        {t('Private demo link (48 h):')} {demoLink}
                    </p>
                )}
                {demo ? (
                    <section className="flex flex-col gap-2 rounded-xl border p-3">
                        <p className="font-medium">{demo.name}</p>
                        <p className="text-muted-foreground">
                            {t('Login: :email', { email: demo.login ?? '' })}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <a
                                href={demo.link}
                                className="underline"
                                target="_blank"
                                rel="noreferrer"
                            >
                                {t('Open the demo (link valid 1 hour)')}
                            </a>
                            <span className="text-xs text-muted-foreground">
                                {t(
                                    'Open it in a private window to stay signed in here.',
                                )}
                            </span>
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
                                {t('Reset now')}
                            </Button>
                        </div>
                    </section>
                ) : (
                    <div>
                        <Button
                            onClick={() =>
                                router.post(PlatformDemoController.create.url())
                            }
                        >
                            {t('Create the demo hotel')}
                        </Button>
                    </div>
                )}
                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('Private demos for prospects')}
                        description={t(
                            'Sent from Leads; deleted after 48 hours.',
                        )}
                    />
                    <ul className="divide-y rounded-xl border">
                        {privateDemos.length === 0 && (
                            <li className="px-3 py-2 text-muted-foreground">
                                {t('None right now.')}
                            </li>
                        )}
                        {privateDemos.map((d) => (
                            <li
                                key={d.id}
                                className="flex flex-wrap gap-2 px-3 py-2"
                            >
                                <span className="font-medium">{d.name}</span>
                                <span className="text-muted-foreground">
                                    {d.opened
                                        ? t('Opened :date', { date: d.opened })
                                        : t('Not opened yet')}
                                </span>
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {t('until :date', {
                                        date: d.expires ?? '',
                                    })}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

PlatformDemo.layout = {
    breadcrumbs: [
        { title: 'Demo hotel', href: PlatformDemoController.index() },
    ],
};
