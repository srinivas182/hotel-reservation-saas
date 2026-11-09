import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ProductController from '@/actions/App/Domains/Product/Http/Controllers/ProductController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { getJson } from '@/lib/http';
import { t } from '@/lib/i18n';

type Idea = {
    id: number;
    title: string;
    body: string | null;
    category: string;
    status: string;
    status_label: string;
    votes: number;
    voted: boolean;
    reply: string | null;
    date: string | null;
};
type Similar = { id: number; title: string; votes: number; status: string };

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const categoryLabel = (c: string) =>
    ({
        whatsapp: t('WhatsApp'),
        assistant: t('Assistant'),
        bookings: t('Bookings'),
        restaurant: t('Restaurant and bar'),
        events: t('Events'),
        billing: t('Billing'),
        other: t('Other'),
    })[c] ?? c;

export default function Ideas({
    filter,
    ideas,
    categories,
}: {
    filter: string;
    ideas: Idea[];
    categories: string[];
}) {
    const form = useForm({ title: '', body: '', category: 'other' });
    const [similar, setSimilar] = useState<Similar[]>([]);
    const timer = useRef<number | undefined>(undefined);

    // Similar ideas first, so votes gather on one idea.
    useEffect(() => {
        window.clearTimeout(timer.current);
        if (form.data.title.trim().length < 5) {
            setSimilar([]);

            return;
        }
        timer.current = window.setTimeout(() => {
            void getJson<{ ideas: Similar[] }>(
                `${ProductController.similar.url()}?q=${encodeURIComponent(form.data.title)}`,
            )
                .then((r) => setSimilar(r.ideas))
                .catch(() => setSimilar([]));
        }, 400);
    }, [form.data.title]);

    return (
        <>
            <Head title={t('Ideas')} />
            <div className="flex max-w-4xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('Ideas')}
                    description={t(
                        'Suggest what would help your hotel, and vote for the ideas you want most. We read every one.',
                    )}
                />
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(ProductController.suggest.url(), {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                    className="grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_200px]"
                >
                    <label className="grid gap-0.5 text-xs">
                        {t('Your idea')}
                        <Input
                            value={form.data.title}
                            maxLength={150}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                        <InputError message={form.errors.title} />
                    </label>
                    <label className="grid gap-0.5 text-xs">
                        {t('Topic')}
                        <select
                            className={selectClass}
                            value={form.data.category}
                            onChange={(e) =>
                                form.setData('category', e.target.value)
                            }
                        >
                            {categories.map((c) => (
                                <option key={c} value={c}>
                                    {categoryLabel(c)}
                                </option>
                            ))}
                        </select>
                    </label>
                    {similar.length > 0 && (
                        <div
                            className="rounded-lg bg-muted/50 p-2 sm:col-span-2"
                            aria-live="polite"
                        >
                            <p className="mb-1 font-medium">
                                {t('Similar ideas — vote for one instead?')}
                            </p>
                            <ul className="flex flex-col gap-1">
                                {similar.map((s) => (
                                    <li
                                        key={s.id}
                                        className="flex items-center gap-2"
                                    >
                                        <span>{s.title}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {t(':count votes', {
                                                count: s.votes,
                                            })}
                                        </span>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    ProductController.vote.url(
                                                        s.id,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Vote')}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <textarea
                        aria-label={t('Details (optional)')}
                        placeholder={t(
                            'Details (optional): what would it change for you?',
                        )}
                        className="min-h-16 rounded-md border border-input bg-transparent px-3 py-2 sm:col-span-2"
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                    />
                    <div>
                        <Button size="sm" disabled={form.processing}>
                            {t('Suggest')}
                        </Button>
                    </div>
                </form>
                <div className="flex flex-wrap gap-2">
                    {(
                        [
                            ['open', t('Open ideas')],
                            ['planned', t('Planned')],
                            ['done', t('Available')],
                        ] as const
                    ).map(([key, label]) => (
                        <Button
                            key={key}
                            size="sm"
                            variant={filter === key ? 'default' : 'outline'}
                            onClick={() =>
                                router.get(
                                    ProductController.ideas.url({
                                        query: { status: key },
                                    }),
                                )
                            }
                        >
                            {label}
                        </Button>
                    ))}
                </div>
                <ul className="flex flex-col gap-2">
                    {ideas.length === 0 && (
                        <li className="text-muted-foreground">
                            {t('No ideas here yet.')}
                        </li>
                    )}
                    {ideas.map((i) => (
                        <li
                            key={i.id}
                            className="flex gap-3 rounded-xl border p-3"
                        >
                            <Button
                                size="sm"
                                variant={i.voted ? 'default' : 'outline'}
                                className="flex h-auto flex-col px-3"
                                aria-pressed={i.voted}
                                disabled={
                                    i.status === 'done' ||
                                    i.status === 'declined'
                                }
                                onClick={() =>
                                    router.post(
                                        ProductController.vote.url(i.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <span aria-hidden="true">▲</span>
                                <span>{i.votes}</span>
                                <span className="sr-only">
                                    {i.voted
                                        ? t('Remove my vote for :title', {
                                              title: i.title,
                                          })
                                        : t('Vote for :title', {
                                              title: i.title,
                                          })}
                                </span>
                            </Button>
                            <div className="flex flex-col gap-1">
                                <p className="font-medium">{i.title}</p>
                                {i.body && (
                                    <p className="text-muted-foreground">
                                        {i.body}
                                    </p>
                                )}
                                <div className="flex flex-wrap items-center gap-2 text-xs">
                                    <Badge
                                        variant={
                                            i.status === 'done'
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {i.status_label}
                                    </Badge>
                                    <span className="text-muted-foreground">
                                        {categoryLabel(i.category)}
                                    </span>
                                </div>
                                {i.reply && (
                                    <p className="rounded-md bg-muted/50 px-2 py-1">
                                        {i.reply}
                                    </p>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Ideas.layout = {
    breadcrumbs: [{ title: 'Ideas', href: ProductController.ideas() }],
};
