import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import PlatformHelpController from '@/actions/App/Domains/Help/Http/Controllers/PlatformHelpController';
import {
    DataTable,
    Field,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    Switch,
    Tabs,
    useTabs,
} from '@/components/page';
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
            className="grid gap-6"
        >
            <FieldGroup title={t('Article')}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('Address (slug)')} error={errors.slug}>
                        <Input
                            value={form.data.slug}
                            onChange={(e) =>
                                form.setData('slug', e.target.value)
                            }
                        />
                    </Field>
                    <Field label={t('Category')}>
                        <select
                            className="h-9"
                            value={form.data.category}
                            onChange={(e) =>
                                form.setData('category', e.target.value)
                            }
                        >
                            {categories.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
                <Field
                    label={t(
                        'Screens this article helps with (comma separated, e.g. hotel/today)',
                    )}
                >
                    <Input
                        value={form.data.screens}
                        onChange={(e) =>
                            form.setData('screens', e.target.value)
                        }
                    />
                </Field>
                <Switch
                    checked={form.data.published}
                    onChange={(v) => form.setData('published', v)}
                    label={t('Published')}
                />
            </FieldGroup>
            {(['fr', 'en'] as const).map((lang) => (
                <FieldGroup
                    key={lang}
                    title={lang === 'fr' ? t('French') : t('English')}
                >
                    <Field label={t('Title')} error={errors[`title.${lang}`]}>
                        <Input
                            value={form.data.title[lang]}
                            onChange={(e) => set('title', lang, e.target.value)}
                        />
                    </Field>
                    <Field
                        label={t('Text (Markdown)')}
                        error={errors[`body.${lang}`]}
                    >
                        <textarea
                            className="min-h-60 font-mono text-xs"
                            value={form.data.body[lang]}
                            onChange={(e) => set('body', lang, e.target.value)}
                        />
                    </Field>
                </FieldGroup>
            ))}
            <Field
                label={
                    uploading
                        ? t('Uploading…')
                        : t(
                              'Insert a picture or animation (PNG, JPG, GIF, WebP)',
                          )
                }
                hint={t('Added at the end of both texts.')}
            >
                <input
                    type="file"
                    accept=".png,.jpg,.jpeg,.gif,.webp"
                    onChange={(e) =>
                        e.target.files?.[0] && void upload(e.target.files[0])
                    }
                />
            </Field>
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => onDone?.()}
                >
                    {t('Cancel')}
                </Button>
                <Button disabled={form.processing}>
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
    const tabs = [
        { key: 'articles', label: t('Articles'), count: articles.length },
        {
            key: 'feedback',
            label: t('What readers miss'),
            count: gaps.length + comments.length,
        },
    ];
    const [tab, setTab] = useTabs(tabs);
    const [editing, setEditing] = useState<Article | 'new' | null>(null);
    const article = editing === 'new' || editing === null ? undefined : editing;
    const categoryLabel = (v: string) =>
        categories.find((c) => c.value === v)?.label ?? v;

    return (
        <>
            <Head title={t('Help centre')} />
            <PageBody>
                <PageHeader
                    title={t('Help centre')}
                    description={t(
                        'Articles in French and English, shown on the website and in the “?” panel of each screen.',
                    )}
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus /> {t('Add article')}
                        </Button>
                    }
                />
                <Tabs tabs={tabs} active={tab} onSelect={setTab} />

                {tab === 'articles' && (
                    <Section
                        flush
                        title={t('Articles')}
                        description={t('Click an article to edit it.')}
                    >
                        <DataTable
                            rows={articles}
                            rowKey={(a) => a.id}
                            empty={t('No articles yet.')}
                            onRowClick={setEditing}
                            columns={[
                                {
                                    key: 'title',
                                    header: t('Title'),
                                    cell: (a) => (
                                        <div>
                                            <div className="font-medium">
                                                {a.title.fr}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                /{a.slug}
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'category',
                                    header: t('Category'),
                                    cell: (a) => categoryLabel(a.category),
                                },
                                {
                                    key: 'votes',
                                    header: t('Helpful'),
                                    align: 'right',
                                    cell: (a) => (
                                        <span className="text-xs whitespace-nowrap text-muted-foreground">
                                            👍 {a.helpful} · 👎 {a.not_helpful}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (a) =>
                                        a.published ? (
                                            <Badge variant="outline">
                                                {t('Published')}
                                            </Badge>
                                        ) : (
                                            <Badge variant="secondary">
                                                {t('Hidden')}
                                            </Badge>
                                        ),
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'feedback' && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        <Section
                            flush
                            title={t('Searches with no answer (60 days)')}
                            description={t(
                                'What hotels looked for and did not find: write these next.',
                            )}
                        >
                            <DataTable
                                rows={gaps}
                                rowKey={(g) => g.q}
                                empty={t('Nothing yet.')}
                                columns={[
                                    {
                                        key: 'q',
                                        header: t('Search'),
                                        cell: (g) => g.q,
                                    },
                                    {
                                        key: 'n',
                                        header: t('Times'),
                                        align: 'right',
                                        cell: (g) => g.n,
                                    },
                                    {
                                        key: 'last',
                                        header: t('Last'),
                                        cell: (g) => (
                                            <span className="text-xs text-muted-foreground">
                                                {g.last}
                                            </span>
                                        ),
                                    },
                                ]}
                            />
                        </Section>
                        <Section flush title={t('“Not helpful” comments')}>
                            <DataTable
                                rows={comments.map((c, i) => ({ ...c, i }))}
                                rowKey={(c) => c.i}
                                empty={t('Nothing yet.')}
                                columns={[
                                    {
                                        key: 'comment',
                                        header: t('Comment'),
                                        cell: (c) => c.comment,
                                    },
                                    {
                                        key: 'slug',
                                        header: t('Article'),
                                        cell: (c) => (
                                            <span className="font-mono text-xs">
                                                /{c.slug}
                                            </span>
                                        ),
                                    },
                                ]}
                            />
                        </Section>
                    </div>
                )}
            </PageBody>
            <SidePanel
                wide
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={article ? article.title.fr : t('Add article')}
            >
                {editing !== null && (
                    <ArticleForm
                        key={article?.id ?? 'new'}
                        article={article}
                        categories={categories}
                        onDone={() => setEditing(null)}
                    />
                )}
            </SidePanel>
        </>
    );
}

PlatformHelp.layout = {
    breadcrumbs: [
        { title: 'Help centre', href: PlatformHelpController.index() },
    ],
};
