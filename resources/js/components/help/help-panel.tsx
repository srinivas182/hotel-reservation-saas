import { usePage } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { getJson, postJson } from '@/lib/http';
import { t } from '@/lib/i18n';

type Summary = { slug: string; title: string; excerpt: string; url: string };
type Article = Summary & { html: string };
type PanelData = { article: Article | null; others: Summary[] };

/**
 * The "?" button: the help article for the current screen in a side panel,
 * with search, without leaving the screen.
 */
export function HelpPanel() {
    const { component } = usePage();
    const [open, setOpen] = useState(false);
    const [data, setData] = useState<PanelData | null>(null);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Summary[] | null>(null);
    const [feedback, setFeedback] = useState<'sent' | null>(null);

    const load = (params: string) => {
        setFeedback(null);
        void getJson<PanelData>(`/help-panel?${params}`)
            .then((d) => {
                setData(d);
                setResults(null);
            })
            .catch(() => setData({ article: null, others: [] }));
    };

    useEffect(() => {
        if (open) {
            load(`screen=${encodeURIComponent(component)}`);
        }
    }, [open, component]);

    const search = (e: React.FormEvent) => {
        e.preventDefault();
        if (query.trim().length < 3) {
            return;
        }
        void getJson<{ results: Summary[] }>(
            `/help-panel?q=${encodeURIComponent(query)}`,
        )
            .then((r) => setResults(r.results))
            .catch(() => setResults([]));
    };

    const vote = (helpful: boolean) => {
        if (data?.article) {
            void postJson(`/help/${data.article.slug}/feedback`, { helpful })
                .then(() => setFeedback('sent'))
                .catch(() => setFeedback('sent'));
        }
    };

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="ml-auto"
                    aria-label={t('Help')}
                >
                    <CircleHelp className="size-5" />
                </Button>
            </SheetTrigger>
            <SheetContent
                side="right"
                className="w-full overflow-y-auto sm:max-w-md"
            >
                <SheetHeader>
                    <SheetTitle>{t('Help')}</SheetTitle>
                    <SheetDescription>
                        {t(
                            'Answers for this screen, and a search of the help centre.',
                        )}
                    </SheetDescription>
                </SheetHeader>
                <div className="flex flex-col gap-4 px-4 pb-6 text-sm">
                    <form
                        onSubmit={search}
                        role="search"
                        className="flex gap-2"
                    >
                        <Input
                            aria-label={t('Search the help centre')}
                            placeholder={t('Search the help centre')}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                        <Button type="submit" size="sm">
                            {t('Search')}
                        </Button>
                    </form>
                    {results !== null && (
                        <ul className="flex flex-col gap-2">
                            {results.length === 0 && (
                                <li className="text-muted-foreground">
                                    {t(
                                        'No article found. Try other words, or contact support from the app.',
                                    )}
                                </li>
                            )}
                            {results.map((r) => (
                                <li key={r.slug}>
                                    <button
                                        type="button"
                                        className="text-left font-medium underline"
                                        onClick={() => load(`slug=${r.slug}`)}
                                    >
                                        {r.title}
                                    </button>
                                    <p className="text-xs text-muted-foreground">
                                        {r.excerpt}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                    {data?.article ? (
                        <article className="flex flex-col gap-2">
                            <h3 className="text-base font-semibold">
                                {data.article.title}
                            </h3>
                            {/* Rendered on the server from Markdown with HTML escaped. */}
                            <div
                                className="prose prose-sm dark:prose-invert max-w-none [&_li]:my-0.5 [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5"
                                dangerouslySetInnerHTML={{
                                    __html: data.article.html,
                                }}
                            />
                            <div className="flex flex-wrap items-center gap-2 border-t pt-3">
                                {feedback === 'sent' ? (
                                    <span role="status">
                                        {t('Thank you for your feedback.')}
                                    </span>
                                ) : (
                                    <>
                                        <span>
                                            {t(
                                                'Did this answer your question?',
                                            )}
                                        </span>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => vote(true)}
                                        >
                                            {t('Yes')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => vote(false)}
                                        >
                                            {t('No')}
                                        </Button>
                                    </>
                                )}
                                <a
                                    href={data.article.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="ml-auto underline"
                                >
                                    {t('Open in the help centre')}
                                </a>
                            </div>
                        </article>
                    ) : (
                        data && (
                            <p className="text-muted-foreground">
                                {t('No article for this screen yet.')}
                            </p>
                        )
                    )}
                    <a
                        href={`/hotel/requests?from=${encodeURIComponent(typeof window === 'undefined' ? '' : window.location.pathname)}&screen=${encodeURIComponent(component)}`}
                        className="underline"
                    >
                        {t('Still stuck? Contact support')}
                    </a>
                    {data && data.others.length > 0 && (
                        <section>
                            <h3 className="mb-1 font-semibold">
                                {t('All articles')}
                            </h3>
                            <ul className="flex flex-col gap-1">
                                {data.others.map((o) => (
                                    <li key={o.slug}>
                                        <button
                                            type="button"
                                            className="text-left underline"
                                            onClick={() =>
                                                load(`slug=${o.slug}`)
                                            }
                                        >
                                            {o.title}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
