import { Head, useForm } from '@inertiajs/react';
import PlatformProductController from '@/actions/App/Domains/Product/Http/Controllers/PlatformProductController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Lang = { fr: string; en: string };
type Note = {
    id: number;
    version: string | null;
    title: Lang;
    body: Lang;
    try_path: string | null;
    media: string | null;
    published_at: string | null;
};
type Idea = {
    id: number;
    title: string;
    body: string | null;
    category: string;
    status: string;
    votes: number;
    reply: string | null;
    hotels: number;
};
type Props = {
    notes: Note[];
    ideas: Idea[];
    statuses: string[];
    version: string;
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function NoteForm({ note, version }: { note?: Note; version: string }) {
    const form = useForm<{
        version: string;
        title: Lang;
        body: Lang;
        try_path: string;
        publish: boolean;
        media: File | null;
    }>({
        version: note?.version ?? version,
        title: note?.title ?? { fr: '', en: '' },
        body: note?.body ?? { fr: '', en: '' },
        try_path: note?.try_path ?? '',
        publish: false,
        media: null,
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({ ...d, try_path: d.try_path || null }));
                form.post(
                    note
                        ? PlatformProductController.updateNote.url(note.id)
                        : PlatformProductController.storeNote.url(),
                    {
                        forceFormData: true,
                        preserveScroll: true,
                        onSuccess: () => !note && form.reset(),
                    },
                );
            }}
            className="grid gap-2 sm:grid-cols-2"
        >
            {(['fr', 'en'] as const).map((l) => (
                <div key={l} className="grid gap-2">
                    <Input
                        aria-label={`${t('Title')} ${l}`}
                        placeholder={`${t('Title')} (${l})`}
                        value={form.data.title[l]}
                        onChange={(e) =>
                            form.setData('title', {
                                ...form.data.title,
                                [l]: e.target.value,
                            })
                        }
                    />
                    <textarea
                        aria-label={`${t('Text (Markdown)')} ${l}`}
                        className="min-h-24 rounded-md border border-input bg-transparent p-2 text-xs"
                        value={form.data.body[l]}
                        onChange={(e) =>
                            form.setData('body', {
                                ...form.data.body,
                                [l]: e.target.value,
                            })
                        }
                    />
                </div>
            ))}
            <label className="grid gap-0.5 text-xs">
                {t('Version')}
                <Input
                    value={form.data.version}
                    onChange={(e) => form.setData('version', e.target.value)}
                />
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('“Try it” opens (e.g. /hotel/demo)')}
                <Input
                    value={form.data.try_path}
                    onChange={(e) => form.setData('try_path', e.target.value)}
                />
                <InputError message={errors.try_path} />
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('Picture or short animation (GIF)')}
                <input
                    type="file"
                    accept=".png,.jpg,.jpeg,.gif,.webp"
                    onChange={(e) =>
                        form.setData('media', e.target.files?.[0] ?? null)
                    }
                />
            </label>
            {!note?.published_at && (
                <label className="flex items-center gap-2 text-xs">
                    <input
                        type="checkbox"
                        checked={form.data.publish}
                        onChange={(e) =>
                            form.setData('publish', e.target.checked)
                        }
                    />
                    {t('Publish now')}
                </label>
            )}
            <InputError message={errors['title.fr'] ?? errors['body.fr']} />
            <div>
                <Button size="sm" disabled={form.processing}>
                    {note ? t('Save') : t('Add the note')}
                </Button>
            </div>
        </form>
    );
}

function IdeaForm({
    idea,
    statuses,
    ideas,
}: {
    idea: Idea;
    statuses: string[];
    ideas: Idea[];
}) {
    const form = useForm({
        status: idea.status,
        reply: idea.reply ?? '',
        merge_into: '',
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    merge_into: d.merge_into ? Number(d.merge_into) : null,
                }));
                form.put(PlatformProductController.decide.url(idea.id), {
                    preserveScroll: true,
                });
            }}
            className="mt-2 grid gap-2 sm:grid-cols-3"
        >
            <select
                aria-label={t('Status')}
                className={selectClass}
                value={form.data.status}
                onChange={(e) => form.setData('status', e.target.value)}
            >
                {statuses.map((s) => (
                    <option key={s} value={s}>
                        {s}
                    </option>
                ))}
            </select>
            <select
                aria-label={t('Merge into')}
                className={selectClass}
                value={form.data.merge_into}
                onChange={(e) => form.setData('merge_into', e.target.value)}
            >
                <option value="">{t('Do not merge')}</option>
                {ideas
                    .filter((i) => i.id !== idea.id)
                    .map((i) => (
                        <option key={i.id} value={i.id}>
                            {t('Merge into: :title', { title: i.title })}
                        </option>
                    ))}
            </select>
            <Button size="sm" disabled={form.processing}>
                {t('Save')}
            </Button>
            <textarea
                aria-label={t('Answer shown to everyone')}
                placeholder={t('Answer shown to everyone (optional)')}
                className="min-h-14 rounded-md border border-input bg-transparent px-3 py-2 sm:col-span-3"
                value={form.data.reply}
                onChange={(e) => form.setData('reply', e.target.value)}
            />
        </form>
    );
}

export default function PlatformProduct({
    notes,
    ideas,
    statuses,
    version,
}: Props) {
    return (
        <>
            <Head title={t('What’s new and ideas')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('What’s new and ideas')}
                    description={t(
                        'Release notes for hotels (shown in the app and in a monthly email), and the ideas they suggest and vote for.',
                    )}
                />
                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('New release note')} />
                    <div className="rounded-xl border p-3">
                        <NoteForm version={version} />
                    </div>
                    {notes.map((n) => (
                        <details key={n.id} className="rounded-xl border p-2">
                            <summary className="cursor-pointer">
                                {n.title.fr} {n.version ? `· ${n.version}` : ''}{' '}
                                {n.published_at
                                    ? `· ${t('published :date', { date: n.published_at })}`
                                    : `· ${t('draft')}`}
                            </summary>
                            <NoteForm note={n} version={version} />
                        </details>
                    ))}
                </section>
                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('Ideas, most wanted first')}
                    />
                    <ul className="flex flex-col gap-2">
                        {ideas.length === 0 && (
                            <li className="text-muted-foreground">
                                {t('No ideas yet.')}
                            </li>
                        )}
                        {ideas.map((i) => (
                            <li key={i.id} className="rounded-xl border p-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge>
                                        {t(':count votes', { count: i.votes })}
                                    </Badge>
                                    <span className="text-xs text-muted-foreground">
                                        {t(':count hotels', {
                                            count: i.hotels,
                                        })}
                                    </span>
                                    <span className="font-medium">
                                        {i.title}
                                    </span>
                                    <Badge variant="outline">{i.status}</Badge>
                                </div>
                                {i.body && (
                                    <p className="text-muted-foreground">
                                        {i.body}
                                    </p>
                                )}
                                <IdeaForm
                                    idea={i}
                                    statuses={statuses}
                                    ideas={ideas}
                                />
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

PlatformProduct.layout = {
    breadcrumbs: [
        {
            title: 'What’s new and ideas',
            href: PlatformProductController.index(),
        },
    ],
};
