import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import HotelSupportController from '@/actions/App/Domains/Support/Http/Controllers/HotelSupportController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { ScreenshotAnnotator } from '@/components/support/screenshot-annotator';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { getJson } from '@/lib/http';
import { t } from '@/lib/i18n';

type Ticket = {
    id: number;
    subject: string;
    category: string;
    status: string;
    late: boolean;
    rating: string | null;
    updated: string | null;
    created: string | null;
};
type Props = {
    hours?: string | null;
    tickets: Ticket[];
    categories: { value: string; label: string }[];
    whatsapp: boolean;
    version: string;
};
type Suggestion = { slug: string; title: string; excerpt: string; url: string };

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
export const statusLabel = (s: string) =>
    ({
        open: t('In progress'),
        waiting_hotel: t('Waiting for you'),
        solved: t('Solved'),
    })[s] ?? s;

export default function Support({
    hours,
    tickets,
    categories,
    whatsapp,
}: Props) {
    const { url } = usePage();
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const form = useForm<{
        subject: string;
        category: string;
        body: string;
        files: File[];
        notify_whatsapp: boolean;
        context: Record<string, string>;
    }>({
        subject: '',
        category: categories[0]?.value ?? 'other',
        body: '',
        files: [],
        notify_whatsapp: whatsapp,
        context: {
            page: params.get('from') ?? '',
            screen: params.get('screen') ?? '',
            browser:
                typeof navigator === 'undefined' ? '' : navigator.userAgent,
            screen_size:
                typeof window === 'undefined'
                    ? ''
                    : `${window.innerWidth}×${window.innerHeight}`,
        },
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [solved, setSolved] = useState(false);
    const [annotating, setAnnotating] = useState<File | null>(null);
    const timer = useRef<number | undefined>(undefined);

    // A: answers from the help centre while the subject is typed.
    useEffect(() => {
        window.clearTimeout(timer.current);
        const q = form.data.subject.trim();
        if (q.length < 4) {
            setSuggestions([]);

            return;
        }
        timer.current = window.setTimeout(() => {
            void getJson<{ results: Suggestion[] }>(
                `/help-panel?source=support&q=${encodeURIComponent(q)}`,
            )
                .then((r) => setSuggestions(r.results.slice(0, 3)))
                .catch(() => setSuggestions([]));
        }, 500);
    }, [form.data.subject]);

    return (
        <>
            <Head title={t('Support')} />
            <div className="flex max-w-4xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('Support')}
                    description={t(
                        'Ask our team for help. We answer here and by email.',
                    )}
                />
                {hours && (
                    <p className="text-muted-foreground">
                        {t('Support hours: :hours', { hours })}
                    </p>
                )}

                {solved ? (
                    <p role="status" className="rounded-lg border px-3 py-2">
                        {t(
                            'Glad it helped! You can still send a request if you need to.',
                        )}{' '}
                        <button
                            type="button"
                            className="underline"
                            onClick={() => setSolved(false)}
                        >
                            {t('New request')}
                        </button>
                    </p>
                ) : (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(HotelSupportController.store.url(), {
                                forceFormData: true,
                            });
                        }}
                        className="grid gap-3 rounded-xl border p-4"
                    >
                        <label className="grid gap-1">
                            <span className="font-medium">{t('Subject')}</span>
                            <Input
                                value={form.data.subject}
                                maxLength={150}
                                onChange={(e) =>
                                    form.setData('subject', e.target.value)
                                }
                            />
                            <InputError message={errors.subject} />
                        </label>
                        {suggestions.length > 0 && (
                            <div
                                className="rounded-lg border bg-muted/40 p-3"
                                aria-live="polite"
                            >
                                <p className="mb-1 font-medium">
                                    {t(
                                        'These articles may answer your question:',
                                    )}
                                </p>
                                <ul className="flex flex-col gap-1">
                                    {suggestions.map((s) => (
                                        <li key={s.slug}>
                                            <a
                                                href={s.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="underline"
                                            >
                                                {s.title}
                                            </a>
                                            <span className="block text-xs text-muted-foreground">
                                                {s.excerpt}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="mt-2"
                                    onClick={() => setSolved(true)}
                                >
                                    {t('That solved it')}
                                </Button>
                            </div>
                        )}
                        <label className="grid gap-1">
                            <span className="font-medium">{t('Topic')}</span>
                            <select
                                className={selectClass}
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
                        </label>
                        <label className="grid gap-1">
                            <span className="font-medium">
                                {t('What happened?')}
                            </span>
                            <textarea
                                className="min-h-28 rounded-md border border-input bg-transparent px-3 py-2"
                                maxLength={5000}
                                value={form.data.body}
                                onChange={(e) =>
                                    form.setData('body', e.target.value)
                                }
                            />
                            <InputError message={errors.body} />
                        </label>
                        <div className="grid gap-1">
                            <span className="font-medium">
                                {t('Screenshots (optional, up to 3)')}
                            </span>
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                aria-label={t('Add a screenshot')}
                                onChange={(e) => {
                                    const f = e.target.files?.[0];
                                    if (f) {
                                        setAnnotating(f);
                                    }
                                    e.target.value = '';
                                }}
                                disabled={form.data.files.length >= 3}
                            />
                            {annotating && (
                                <ScreenshotAnnotator
                                    file={annotating}
                                    onDone={(f) => {
                                        form.setData('files', [
                                            ...form.data.files,
                                            f,
                                        ]);
                                        setAnnotating(null);
                                    }}
                                />
                            )}
                            {form.data.files.length > 0 && (
                                <p className="text-xs text-muted-foreground">
                                    {t(':count picture(s) attached', {
                                        count: form.data.files.length,
                                    })}
                                </p>
                            )}
                            <InputError message={errors['files.0']} />
                        </div>
                        {whatsapp && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={form.data.notify_whatsapp}
                                    onChange={(e) =>
                                        form.setData(
                                            'notify_whatsapp',
                                            e.target.checked,
                                        )
                                    }
                                />
                                {t(
                                    'Also tell me on WhatsApp when support answers',
                                )}
                            </label>
                        )}
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'We automatically include the page, your role, the app version and your browser, to help us understand.',
                            )}
                        </p>
                        <div>
                            <Button
                                disabled={
                                    form.processing || annotating !== null
                                }
                            >
                                {t('Send the request')}
                            </Button>
                        </div>
                    </form>
                )}

                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Your requests')} />
                    <ul className="divide-y rounded-xl border">
                        {tickets.length === 0 && (
                            <li className="px-3 py-2 text-muted-foreground">
                                {t('No requests yet.')}
                            </li>
                        )}
                        {tickets.map((tk) => (
                            <li
                                key={tk.id}
                                className="flex flex-wrap items-center gap-2 px-3 py-2"
                            >
                                <Link
                                    href={HotelSupportController.show.url(
                                        tk.id,
                                    )}
                                    className="font-medium underline"
                                >
                                    #{tk.id} {tk.subject}
                                </Link>
                                <span className="text-muted-foreground">
                                    {tk.category}
                                </span>
                                <Badge
                                    variant={
                                        tk.status === 'waiting_hotel'
                                            ? 'destructive'
                                            : tk.status === 'solved'
                                              ? 'secondary'
                                              : 'outline'
                                    }
                                >
                                    {statusLabel(tk.status)}
                                </Badge>
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {tk.updated}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

Support.layout = {
    breadcrumbs: [{ title: 'Support', href: HotelSupportController.index() }],
};
