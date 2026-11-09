import { Head, Link, router } from '@inertiajs/react';
import ProductController from '@/actions/App/Domains/Product/Http/Controllers/ProductController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type Note = {
    id: number;
    version: string | null;
    title: string;
    html: string;
    media: string | null;
    try: string | null;
    date: string | null;
    new: boolean;
};

export default function WhatsNew({
    notes,
    productEmails,
}: {
    notes: Note[];
    productEmails: boolean;
}) {
    return (
        <>
            <Head title={t('What’s new')} />
            <div className="flex max-w-3xl flex-col gap-6 p-4 text-sm">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title={t('What’s new')}
                        description={t(
                            'New features and improvements, most recent first.',
                        )}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href={ProductController.ideas.url()}
                            className="underline"
                        >
                            {t('Suggest an idea')}
                        </Link>
                        <label className="flex items-center gap-2 text-xs">
                            <input
                                type="checkbox"
                                checked={productEmails}
                                onChange={(e) =>
                                    router.put(
                                        ProductController.emails.url(),
                                        { on: e.target.checked },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                            {t('Monthly email of what’s new')}
                        </label>
                    </div>
                </div>
                {notes.length === 0 && (
                    <p className="text-muted-foreground">{t('Nothing yet.')}</p>
                )}
                {notes.map((n) => (
                    <article
                        key={n.id}
                        className="flex flex-col gap-2 rounded-xl border p-4"
                    >
                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            {n.new && <Badge>{t('New')}</Badge>}
                            <span>{n.date}</span>
                            {n.version && <span>· {n.version}</span>}
                        </div>
                        <h2 className="text-base font-semibold">{n.title}</h2>
                        {n.media && (
                            <img
                                src={n.media}
                                alt=""
                                className="max-h-80 w-full rounded-lg border object-contain"
                                loading="lazy"
                            />
                        )}
                        {/* Rendered on the server from Markdown with HTML escaped. */}
                        <div
                            className="max-w-none [&_li]:my-0.5 [&_ul]:list-disc [&_ul]:pl-5"
                            dangerouslySetInnerHTML={{ __html: n.html }}
                        />
                        {n.try && (
                            <div>
                                <Button asChild size="sm">
                                    <Link href={n.try}>{t('Try it')}</Link>
                                </Button>
                            </div>
                        )}
                    </article>
                ))}
            </div>
        </>
    );
}

WhatsNew.layout = {
    breadcrumbs: [{ title: 'What’s new', href: ProductController.whatsNew() }],
};
