import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformHelpController from '@/actions/App/Domains/Help/Http/Controllers/PlatformHelpController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Lang = { fr: string; en: string };
type Article = {
    id: number;
    slug: string;
    category: string;
    title: Lang;
    body: Lang;
    screens: string;
    published: boolean;
    helpful: number;
    not_helpful: number;
};
type Props = {
    articles: Article[];
    categories: { value: string; label: string }[];
    gaps: { q: string; n: number; last: string }[];
    comments: { slug: string; comment: string; created_at: string }[];
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const blank: Omit<Article, 'id' | 'helpful' | 'not_helpful'> = {
    slug: '',
    category: 'start',
    title: { fr: '', en: '' },
    body: { fr: '', en: '' },
    screens: '',
    published: true,
};

function xsrf(): string {
    const match = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1]) : '';
}

function ArticleForm({
    article,
    categories,
    onDone,
}: {
    article?: Article;
    categories: Props['categories'];
    onDone?: () => void;
}) {
    const form = useForm(
        article
            ? {
                  slug: article.slug,
                  category: article.category,
                  title: article.title,
                  body: article.body,
                  screens: article.screens,
                  published: article.published,
              }
            : blank,
    );
    const errors = form.errors as Record<string, string | undefined>;
    const [uploading, setUploading] = useState(false);
    const set = (field: 'title' | 'body', lang: 'fr' | 'en', value: string) =>
        form.setData(field, { ...form.data[field], [lang]: value });

    const upload = async (file: File) => {
        setUploading(true);
        const data = new FormData();
        data.append('file', file);
        try {
            const response = await fetch(PlatformHelpController.media.url(), {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            });
            const json = (await response.json()) as { markdown?: string };
            if (json.markdown) {
                form.setData('body', {
                    fr: `${form.data.body.fr}\n\n${json.markdown}\n`,
                    en: `${form.data.body.en}\n\n${json.markdown}\n`,
                });
            }
        } finally {
            setUploading(false);
        }
    };

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                const options = {
                    preserveScroll: true,
                    onSuccess: () => onDone?.(),
                };
                if (article) {
                    form.put(
                        PlatformHelpController.update.url(article.id),
                        options,
                    );
                } else {
                    form.post(PlatformHelpController.store.url(), options);
                }
            }}
            className="mt-2 grid gap-2 text-sm sm:grid-cols-2"
        >
            <label className="grid gap-0.5 text-xs">
                {t('Address (slug)')}
                <Input
                    value={form.data.slug}
                    onChange={(e) => form.setData('slug', e.target.value)}
                />
                <InputError message={errors.slug} />
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('Category')}
                <select
                    className={selectClass}
                    value={form.data.category}
                    onChange={(e) => form.setData('category', e.target.value)}
                >
                    {categories.map((c) => (
                        <option key={c.value} value={c.value}>
                            {c.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-0.5 text-xs sm:col-span-2">
                {t(
                    'Screens this article helps with (comma separated, e.g. hotel/today)',
                )}
                <Input
                    value={form.data.screens}
                    onChange={(e) => form.setData('screens', e.target.value)}
                />
            </label>
            {(['fr', 'en'] as const).map((lang) => (
                <div key={lang} className="grid gap-2">
                    <Input
                        aria-label={`${t('Title')} ${lang}`}
                        placeholder={`${t('Title')} (${lang})`}
                        value={form.data.title[lang]}
                        onChange={(e) => set('title', lang, e.target.value)}
                    />
                    <textarea
                        aria-label={`${t('Text (Markdown)')} ${lang}`}
                        className="min-h-60 rounded-md border border-input bg-transparent p-2 font-mono text-xs"
                        value={form.data.body[lang]}
                        onChange={(e) => set('body', lang, e.target.value)}
                    />
                </div>
            ))}
            <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={form.data.published}
                        onChange={(e) =>
                            form.setData('published', e.target.checked)
                        }
                    />
                    {t('Published')}
                </label>
                <label className="text-xs">
                    {uploading
                        ? t('Uploading…')
                        : t(
                              'Insert a picture or animation (PNG, JPG, GIF, WebP)',
                          )}
                    <input
                        type="file"
                        accept=".png,.jpg,.jpeg,.gif,.webp"
                        className="ml-2"
                        onChange={(e) =>
                            e.target.files?.[0] &&
                            void upload(e.target.files[0])
                        }
                    />
                </label>
                <Button size="sm" disabled={form.processing}>
                    {article ? t('Save') : t('Add article')}
                </Button>
            </div>
        </form>
    );
}

export default function PlatformHelp({
    articles,
    categories,
    gaps,
    comments,
}: Props) {
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title={t('Help centre')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 text-sm">
                <div className="flex items-start justify-between gap-2">
                    <Heading
                        title={t('Help centre')}
                        description={t(
                            'Articles in French and English, shown on the website and in the “?” panel of each screen.',
                        )}
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setAdding(!adding)}
                    >
                        {adding ? t('Close') : t('Add article')}
                    </Button>
                </div>
                {adding && (
                    <div className="rounded-xl border p-3">
                        <ArticleForm
                            categories={categories}
                            onDone={() => setAdding(false)}
                        />
                    </div>
                )}

                <section className="grid gap-4 md:grid-cols-2">
                    <div className="flex flex-col gap-2">
                        <Heading
                            variant="small"
                            title={t('Searches with no answer (60 days)')}
                            description={t(
                                'What hotels looked for and did not find: write these next.',
                            )}
                        />
                        <ul className="divide-y rounded-xl border">
                            {gaps.length === 0 && (
                                <li className="px-3 py-2 text-muted-foreground">
                                    {t('Nothing yet.')}
                                </li>
                            )}
                            {gaps.map((g) => (
                                <li
                                    key={g.q}
                                    className="flex justify-between gap-2 px-3 py-1.5"
                                >
                                    <span>{g.q}</span>
                                    <Badge variant="outline">{g.n}</Badge>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="flex flex-col gap-2">
                        <Heading
                            variant="small"
                            title={t('“Not helpful” comments')}
                        />
                        <ul className="divide-y rounded-xl border">
                            {comments.length === 0 && (
                                <li className="px-3 py-2 text-muted-foreground">
                                    {t('Nothing yet.')}
                                </li>
                            )}
                            {comments.map((c, i) => (
                                <li key={i} className="px-3 py-1.5">
                                    <span className="font-mono text-xs">
                                        {c.slug}
                                    </span>{' '}
                                    · {c.comment}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Articles')} />
                    {articles.map((a) => (
                        <details key={a.id} className="rounded-xl border p-2">
                            <summary className="flex cursor-pointer flex-wrap items-center gap-2">
                                {a.title.fr}
                                <span className="text-xs text-muted-foreground">
                                    /{a.slug}
                                </span>
                                {!a.published && (
                                    <Badge variant="outline">
                                        {t('Hidden')}
                                    </Badge>
                                )}
                                <span className="ml-auto text-xs text-muted-foreground">
                                    👍 {a.helpful} · 👎 {a.not_helpful}
                                </span>
                            </summary>
                            <ArticleForm article={a} categories={categories} />
                        </details>
                    ))}
                </section>
            </div>
        </>
    );
}

PlatformHelp.layout = {
    breadcrumbs: [
        { title: 'Help centre', href: PlatformHelpController.index() },
    ],
};
