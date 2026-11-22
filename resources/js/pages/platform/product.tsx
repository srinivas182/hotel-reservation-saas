import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import PlatformProductController from '@/actions/App/Domains/Product/Http/Controllers/PlatformProductController';
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

function NoteForm({
    note,
    version,
    onDone,
}: {
    note?: Note;
    version: string;
    onDone: () => void;
}) {
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
                        onSuccess: onDone,
                    },
                );
            }}
            className="grid gap-6"
        >
            {(['fr', 'en'] as const).map((l) => (
                <FieldGroup
                    key={l}
                    title={l === 'fr' ? t('French') : t('English')}
                >
                    <Field label={t('Title')} error={errors[`title.${l}`]}>
                        <Input
                            value={form.data.title[l]}
                            onChange={(e) =>
                                form.setData('title', {
                                    ...form.data.title,
                                    [l]: e.target.value,
                                })
                            }
                        />
                    </Field>
                    <Field
                        label={t('Text (Markdown)')}
                        error={errors[`body.${l}`]}
                    >
                        <textarea
                            className="min-h-28"
                            value={form.data.body[l]}
                            onChange={(e) =>
                                form.setData('body', {
                                    ...form.data.body,
                                    [l]: e.target.value,
                                })
                            }
                        />
                    </Field>
                </FieldGroup>
            ))}
            <FieldGroup title={t('Details')}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('Version')}>
                        <Input
                            value={form.data.version}
                            onChange={(e) =>
                                form.setData('version', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label={t('“Try it” opens (e.g. /hotel/demo)')}
                        error={errors.try_path}
                    >
                        <Input
                            value={form.data.try_path}
                            onChange={(e) =>
                                form.setData('try_path', e.target.value)
                            }
                        />
                    </Field>
                </div>
                <Field label={t('Picture or short animation (GIF)')}>
                    <input
                        type="file"
                        accept=".png,.jpg,.jpeg,.gif,.webp"
                        onChange={(e) =>
                            form.setData('media', e.target.files?.[0] ?? null)
                        }
                    />
                </Field>
                {!note?.published_at && (
                    <Switch
                        checked={form.data.publish}
                        onChange={(v) => form.setData('publish', v)}
                        label={t('Publish now')}
                        hint={t(
                            'Hotels see it in the app at once and in the next monthly email.',
                        )}
                    />
                )}
            </FieldGroup>
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button disabled={form.processing}>
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
    onDone,
}: {
    idea: Idea;
    statuses: string[];
    ideas: Idea[];
    onDone: () => void;
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
                    onSuccess: onDone,
                });
            }}
            className="grid gap-4"
        >
            {idea.body && (
                <p className="rounded-lg bg-muted/50 p-3 text-sm">
                    {idea.body}
                </p>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('Status')}>
                    <select
                        className="h-9"
                        value={form.data.status}
                        onChange={(e) => form.setData('status', e.target.value)}
                    >
                        {statuses.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field
                    label={t('Merge into')}
                    hint={t('Votes move to the other idea.')}
                >
                    <select
                        className="h-9"
                        value={form.data.merge_into}
                        onChange={(e) =>
                            form.setData('merge_into', e.target.value)
                        }
                    >
                        <option value="">{t('Do not merge')}</option>
                        {ideas
                            .filter((i) => i.id !== idea.id)
                            .map((i) => (
                                <option key={i.id} value={i.id}>
                                    {i.title}
                                </option>
                            ))}
                    </select>
                </Field>
            </div>
            <Field label={t('Answer shown to everyone (optional)')}>
                <textarea
                    className="min-h-24"
                    value={form.data.reply}
                    onChange={(e) => form.setData('reply', e.target.value)}
                />
            </Field>
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button disabled={form.processing}>{t('Save')}</Button>
            </div>
        </form>
    );
}

export default function PlatformProduct({
    notes,
    ideas,
    statuses,
    version,
}: Props) {
    const tabs = [
        { key: 'notes', label: t('Release notes'), count: notes.length },
        { key: 'ideas', label: t('Ideas'), count: ideas.length },
    ];
    const [tab, setTab] = useTabs(tabs);
    const [note, setNote] = useState<Note | 'new' | null>(null);
    const [idea, setIdea] = useState<Idea | null>(null);
    const editing = note === 'new' || note === null ? undefined : note;

    return (
        <>
            <Head title={t('What’s new and ideas')} />
            <PageBody>
                <PageHeader
                    title={t('What’s new and ideas')}
                    description={t(
                        'Release notes for hotels (shown in the app and in a monthly email), and the ideas they suggest and vote for.',
                    )}
                    actions={
                        tab === 'notes' && (
                            <Button onClick={() => setNote('new')}>
                                <Plus /> {t('New release note')}
                            </Button>
                        )
                    }
                />
                <Tabs tabs={tabs} active={tab} onSelect={setTab} />

                {tab === 'notes' && (
                    <Section
                        flush
                        title={t('Release notes')}
                        description={t('Click a note to edit it.')}
                    >
                        <DataTable
                            rows={notes}
                            rowKey={(n) => n.id}
                            empty={t('No release notes yet.')}
                            onRowClick={setNote}
                            columns={[
                                {
                                    key: 'title',
                                    header: t('Title'),
                                    cell: (n) => (
                                        <span className="font-medium">
                                            {n.title.fr}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'version',
                                    header: t('Version'),
                                    cell: (n) => n.version ?? '—',
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (n) =>
                                        n.published_at ? (
                                            <Badge>{t('Published')}</Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                {t('Draft')}
                                            </Badge>
                                        ),
                                },
                                {
                                    key: 'date',
                                    header: t('Published on'),
                                    cell: (n) => n.published_at ?? '—',
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'ideas' && (
                    <Section
                        flush
                        title={t('Ideas, most wanted first')}
                        description={t(
                            'Suggested by hotels. Click an idea to answer it or change its status.',
                        )}
                    >
                        <DataTable
                            rows={ideas}
                            rowKey={(i) => i.id}
                            empty={t('No ideas yet.')}
                            onRowClick={setIdea}
                            columns={[
                                {
                                    key: 'votes',
                                    header: t('Votes'),
                                    align: 'right',
                                    cell: (i) => (
                                        <span className="font-semibold">
                                            {i.votes}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'title',
                                    header: t('Idea'),
                                    cell: (i) => (
                                        <div>
                                            <div className="font-medium">
                                                {i.title}
                                            </div>
                                            {i.body && (
                                                <div className="line-clamp-1 text-xs text-muted-foreground">
                                                    {i.body}
                                                </div>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'category',
                                    header: t('Category'),
                                    cell: (i) => i.category,
                                },
                                {
                                    key: 'hotels',
                                    header: t('Hotels'),
                                    align: 'right',
                                    cell: (i) => i.hotels,
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (i) => (
                                        <Badge variant="outline">
                                            {i.status}
                                        </Badge>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}
            </PageBody>

            <SidePanel
                open={note !== null}
                onClose={() => setNote(null)}
                title={editing ? t('Edit the note') : t('New release note')}
            >
                {note !== null && (
                    <NoteForm
                        key={editing?.id ?? 'new'}
                        note={editing}
                        version={version}
                        onDone={() => setNote(null)}
                    />
                )}
            </SidePanel>
            <SidePanel
                open={idea !== null}
                onClose={() => setIdea(null)}
                title={idea?.title ?? ''}
                description={
                    idea
                        ? t(':count votes from :hotels hotels', {
                              count: idea.votes,
                              hotels: idea.hotels,
                          })
                        : undefined
                }
            >
                {idea && (
                    <IdeaForm
                        key={idea.id}
                        idea={idea}
                        statuses={statuses}
                        ideas={ideas}
                        onDone={() => setIdea(null)}
                    />
                )}
            </SidePanel>
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
