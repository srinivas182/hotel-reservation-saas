import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AssistantSettingsController from '@/actions/App/Domains/Assistant/Http/Controllers/AssistantSettingsController';
import KnowledgeController from '@/actions/App/Domains/Assistant/Http/Controllers/KnowledgeController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Entry = { section: string; question: string | null; content: string };

type Props = {
    locale: string;
    locales: { code: string; label: string }[];
    sections: { value: string; label: string }[];
    entries: Entry[];
    versions: { version: number; by: string | null; at: string }[];
    preview?: string;
};

export default function Knowledge({
    locale,
    locales,
    sections,
    entries,
    versions,
    preview,
}: Props) {
    const form = useForm({ locale, entries });
    const errors = form.errors as Record<string, string | undefined>;
    const [showPreview, setShowPreview] = useState(false);

    const setEntry = (index: number, entry: Partial<Entry>) =>
        form.setData(
            'entries',
            form.data.entries.map((e, i) =>
                i === index ? { ...e, ...entry } : e,
            ),
        );
    const add = (section: string) =>
        form.setData('entries', [
            ...form.data.entries,
            { section, question: section === 'faq' ? '' : null, content: '' },
        ]);
    const remove = (index: number) =>
        form.setData(
            'entries',
            form.data.entries.filter((_, i) => i !== index),
        );

    return (
        <>
            <Head title={t('Information pack')} />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title={t('Information pack')}
                        description={t(
                            'What the assistant may tell guests. Room categories, check-in times, contact details and languages are added automatically. Prices always come from the rates.',
                        )}
                    />
                    <div className="flex gap-2">
                        {locales.map((l) => (
                            <Button
                                key={l.code}
                                size="sm"
                                variant={
                                    l.code === locale ? 'default' : 'outline'
                                }
                                onClick={() =>
                                    router.get(
                                        KnowledgeController.show.url({
                                            query: { locale: l.code },
                                        }),
                                    )
                                }
                            >
                                {l.label}
                            </Button>
                        ))}
                    </div>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(KnowledgeController.update.url(), {
                            preserveScroll: true,
                        });
                    }}
                    className="flex flex-col gap-6"
                >
                    {sections.map((section) => {
                        const items = form.data.entries
                            .map((entry, index) => ({ entry, index }))
                            .filter(
                                ({ entry }) => entry.section === section.value,
                            );

                        return (
                            <section
                                key={section.value}
                                className="flex flex-col gap-2 rounded-xl border p-4"
                            >
                                <div className="flex items-center justify-between">
                                    <h2 className="font-medium">
                                        {section.label}
                                    </h2>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => add(section.value)}
                                    >
                                        {section.value === 'faq'
                                            ? t('Add question')
                                            : t('Add text')}
                                    </Button>
                                </div>
                                {items.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('Nothing yet.')}
                                    </p>
                                )}
                                {items.map(({ entry, index }) => (
                                    <div
                                        key={index}
                                        className="flex flex-col gap-1"
                                    >
                                        {section.value === 'faq' && (
                                            <Input
                                                aria-label={t('Question')}
                                                placeholder={t('Question')}
                                                value={entry.question ?? ''}
                                                onChange={(e) =>
                                                    setEntry(index, {
                                                        question:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                        <textarea
                                            aria-label={
                                                section.value === 'faq'
                                                    ? t('Answer')
                                                    : section.label
                                            }
                                            className="min-h-20 rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                            maxLength={3000}
                                            value={entry.content}
                                            onChange={(e) =>
                                                setEntry(index, {
                                                    content: e.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `entries.${index}.content`
                                                ] ??
                                                errors[
                                                    `entries.${index}.question`
                                                ]
                                            }
                                        />
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            className="w-fit text-destructive"
                                            onClick={() => remove(index)}
                                        >
                                            {t('Remove')}
                                        </Button>
                                    </div>
                                ))}
                            </section>
                        );
                    })}
                    <div className="flex flex-wrap gap-2">
                        <Button disabled={form.processing}>
                            {t('Save draft')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.isDirty}
                            title={
                                form.isDirty
                                    ? t('Save the draft first')
                                    : undefined
                            }
                            onClick={() =>
                                window.confirm(
                                    t(
                                        'Publish the draft in every language? The assistant will use it straight away.',
                                    ),
                                ) &&
                                router.post(
                                    KnowledgeController.publish.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('Publish')}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => {
                                setShowPreview(true);
                                router.reload({ only: ['preview'] });
                            }}
                        >
                            {t('Preview what the assistant sees')}
                        </Button>
                    </div>
                </form>

                {showPreview && preview && (
                    <pre className="overflow-x-auto rounded-xl border bg-muted/40 p-4 text-xs whitespace-pre-wrap">
                        {preview}
                    </pre>
                )}

                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Published versions')} />
                    {versions.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('Not published yet')}
                        </p>
                    )}
                    <ul className="text-sm">
                        {versions.map((v) => (
                            <li key={v.version}>
                                {t('Version :version', { version: v.version })}{' '}
                                · {v.at} · {v.by ?? '—'}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

Knowledge.layout = {
    breadcrumbs: [
        { title: 'AI assistant', href: AssistantSettingsController.show() },
        { title: 'Information pack', href: KnowledgeController.show() },
    ],
};
