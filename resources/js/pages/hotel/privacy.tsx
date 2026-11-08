import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import GuestDataController from '@/actions/App/Domains/Privacy/Http/Controllers/GuestDataController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Props = {
    q: string;
    guests: {
        id: string;
        name: string;
        phone: string | null;
        email: string | null;
    }[];
};

export default function Privacy({ q, guests }: Props) {
    const [query, setQuery] = useState(q);

    return (
        <>
            <Head title={t('Guest data requests')} />
            <div className="flex max-w-4xl flex-col gap-4 p-4 text-sm">
                <Heading
                    title={t('Guest data requests')}
                    description={t(
                        'When a guest asks to see or delete their data: find them, then download their data or anonymise them. Bookings and amounts stay for your accounts.',
                    )}
                />
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            GuestDataController.index.url({
                                query: { q: query },
                            }),
                        );
                    }}
                    className="flex gap-2"
                >
                    <Input
                        aria-label={t('Name, phone or email')}
                        placeholder={t('Name, phone or email')}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                    <Button>{t('Search')}</Button>
                </form>
                {q !== '' && guests.length === 0 && (
                    <p className="text-muted-foreground">
                        {t('No guest found.')}
                    </p>
                )}
                <ul className="divide-y rounded-xl border">
                    {guests.map((g) => (
                        <li
                            key={g.id}
                            className="flex flex-wrap items-center gap-3 px-3 py-2"
                        >
                            <span className="font-medium">{g.name}</span>
                            <span className="text-muted-foreground">
                                {[g.phone, g.email].filter(Boolean).join(' · ')}
                            </span>
                            <span className="ml-auto flex gap-2">
                                <a
                                    href={GuestDataController.export.url(g.id)}
                                    className="underline"
                                >
                                    {t('Download data')}
                                </a>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() => {
                                        const confirm = window.prompt(
                                            t(
                                                'This cannot be undone. Type ANONYMISE to remove this guest’s name, contact details and messages.',
                                            ),
                                        );
                                        if (confirm) {
                                            router.post(
                                                GuestDataController.anonymise.url(
                                                    g.id,
                                                ),
                                                {
                                                    confirm: confirm
                                                        .trim()
                                                        .toUpperCase(),
                                                },
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    {t('Anonymise')}
                                </Button>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Privacy.layout = {
    breadcrumbs: [
        { title: 'Guest data requests', href: GuestDataController.index() },
    ],
};
