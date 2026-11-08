import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import SetupController from '@/actions/App/Domains/Onboarding/Http/Controllers/SetupController';
import { GoLivePanel } from '@/components/setup/golive-panel';
import type { GoLiveState } from '@/components/setup/golive-panel';
import { Questionnaire } from '@/components/setup/questionnaire';
import type { QuestionnaireProps } from '@/components/setup/questionnaire';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Step = {
    key: string;
    title: string;
    description: string;
    done: boolean;
    skipped: boolean;
    optional: boolean;
    url: string;
};
type ImportRow = {
    line: number;
    errors: string[];
    number?: string;
    guest?: string;
    category?: string;
    check_in?: string | null;
    check_out?: string | null;
};
type Props = {
    steps: Step[];
    summary: { done: number; total: number; complete: boolean };
    categories: { id: string; code: string; name: string; rooms: number }[];
    suggestions: string[];
    firstNumber: number;
    columns: { rooms: string[]; bookings: string[] };
    can: { rooms: boolean; bookings: boolean };
    questionnaire: QuestionnaireProps;
    golive: GoLiveState;
};

function QuickRooms({
    suggestions,
    firstNumber,
}: {
    suggestions: string[];
    firstNumber: number;
}) {
    const form = useForm({
        categories: suggestions
            .slice(0, 3)
            .map((name, i) => ({ name, count: i === 0 ? 10 : 2, adults: 2 })),
        first_number: firstNumber,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const total = form.data.categories.reduce((sum, c) => sum + c.count, 0);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(SetupController.quickRooms.url(), {
                    preserveScroll: true,
                });
            }}
            className="flex flex-col gap-2 rounded-lg border p-3 text-sm"
        >
            <p className="text-muted-foreground">
                {t(
                    'Name each category and how many rooms it has. Room numbers are created for you and can be changed later.',
                )}
            </p>
            {form.data.categories.map((c, i) => (
                <div key={i} className="flex flex-wrap items-center gap-2">
                    <Input
                        aria-label={t('Category')}
                        className="w-48"
                        value={c.name}
                        onChange={(e) =>
                            form.setData(
                                'categories',
                                form.data.categories.map((x, j) =>
                                    j === i
                                        ? { ...x, name: e.target.value }
                                        : x,
                                ),
                            )
                        }
                    />
                    <label className="flex items-center gap-1">
                        <Input
                            type="number"
                            min={1}
                            className="w-20"
                            value={c.count}
                            onChange={(e) =>
                                form.setData(
                                    'categories',
                                    form.data.categories.map((x, j) =>
                                        j === i
                                            ? {
                                                  ...x,
                                                  count: Number(e.target.value),
                                              }
                                            : x,
                                    ),
                                )
                            }
                        />
                        {t('rooms')}
                    </label>
                    <label className="flex items-center gap-1">
                        <Input
                            type="number"
                            min={1}
                            max={10}
                            className="w-16"
                            value={c.adults}
                            onChange={(e) =>
                                form.setData(
                                    'categories',
                                    form.data.categories.map((x, j) =>
                                        j === i
                                            ? {
                                                  ...x,
                                                  adults: Number(
                                                      e.target.value,
                                                  ),
                                              }
                                            : x,
                                    ),
                                )
                            }
                        />
                        {t('adults max')}
                    </label>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            form.setData(
                                'categories',
                                form.data.categories.filter((_, j) => j !== i),
                            )
                        }
                    >
                        {t('Remove')}
                    </Button>
                </div>
            ))}
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        form.setData('categories', [
                            ...form.data.categories,
                            { name: '', count: 1, adults: 2 },
                        ])
                    }
                >
                    {t('Add a category')}
                </Button>
                <label className="flex items-center gap-1">
                    {t('First room number')}
                    <Input
                        type="number"
                        min={1}
                        className="w-24"
                        value={form.data.first_number}
                        onChange={(e) =>
                            form.setData('first_number', Number(e.target.value))
                        }
                    />
                </label>
                <Button size="sm" disabled={form.processing || total === 0}>
                    {t('Create :count rooms', { count: total })}
                </Button>
            </div>
            <InputError message={errors.categories} />
        </form>
    );
}

function Importer({
    kind,
    columns,
}: {
    kind: 'rooms' | 'bookings';
    columns: string[];
}) {
    const page = usePage<{
        flash?: {
            import?: { kind: string; token: string; rows: ImportRow[] };
            import_result?: {
                saved: number;
                refused: { line: number; reason: string }[];
            };
        };
    }>();
    const flash = page.props.flash ?? {};
    const preview =
        flash.import && flash.import.kind === kind ? flash.import : null;
    const [result, setResult] = useState<{
        saved: number;
        refused: { line: number; reason: string }[];
    } | null>(null);
    const form = useForm<{ file: File | null }>({ file: null });
    const errors = form.errors as Record<string, string | undefined>;
    const valid = preview
        ? preview.rows.filter((r) => r.errors.length === 0).length
        : 0;

    return (
        <div className="flex flex-col gap-2 rounded-lg border p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <a
                    href={SetupController.template.url(kind)}
                    className="underline"
                >
                    {t('Download the template')}
                </a>
                <span className="text-xs text-muted-foreground">
                    {t('Columns: :columns', { columns: columns.join(', ') })}
                </span>
            </div>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(SetupController.preview.url(kind), {
                        forceFormData: true,
                        preserveScroll: true,
                    });
                }}
                className="flex flex-wrap items-center gap-2"
            >
                <input
                    type="file"
                    accept=".csv,text/csv"
                    aria-label={t('File')}
                    onChange={(e) =>
                        form.setData('file', e.target.files?.[0] ?? null)
                    }
                />
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!form.data.file || form.processing}
                >
                    {t('Check the file')}
                </Button>
                <InputError message={errors.file} />
            </form>
            {preview && (
                <>
                    <p>
                        {t(
                            ':valid of :total lines are ready to import. Lines with problems are skipped.',
                            { valid, total: preview.rows.length },
                        )}
                    </p>
                    <ul className="max-h-64 overflow-y-auto rounded border text-xs">
                        {preview.rows.map((r) => (
                            <li
                                key={r.line}
                                className={
                                    r.errors.length
                                        ? 'px-2 py-1 text-destructive'
                                        : 'px-2 py-1'
                                }
                            >
                                {t('Line :line', { line: r.line })}:{' '}
                                {String(r.number ?? r.guest ?? '')}{' '}
                                {String(r.category ?? '')}{' '}
                                {r.check_in
                                    ? `${String(r.check_in)} → ${String(r.check_out)}`
                                    : ''}
                                {r.errors.length > 0 &&
                                    ` — ${r.errors.join(' ')}`}
                            </li>
                        ))}
                    </ul>
                    <div>
                        <Button
                            size="sm"
                            disabled={valid === 0}
                            onClick={() =>
                                router.post(
                                    SetupController.confirm.url(),
                                    { token: preview.token },
                                    {
                                        preserveScroll: true,
                                        onFlash: (f) =>
                                            setResult(
                                                (
                                                    f as {
                                                        import_result?: typeof result;
                                                    }
                                                ).import_result ?? null,
                                            ),
                                    },
                                )
                            }
                        >
                            {t('Import :count lines', { count: valid })}
                        </Button>
                    </div>
                </>
            )}
            {(result ?? flash.import_result) &&
                kind === 'bookings' &&
                ((result ?? flash.import_result)?.refused.length ?? 0) > 0 && (
                    <ul className="text-xs text-destructive">
                        {(result ?? flash.import_result)?.refused.map((r) => (
                            <li key={r.line}>
                                {t('Line :line', { line: r.line })}: {r.reason}
                            </li>
                        ))}
                    </ul>
                )}
        </div>
    );
}

export default function Setup({
    steps,
    summary,
    categories,
    suggestions,
    firstNumber,
    columns,
    can,
    questionnaire,
    golive,
}: Props) {
    const [open, setOpen] = useState<string | null>(
        steps.find((s) => !s.done && !s.skipped)?.key ?? null,
    );

    return (
        <>
            <Head title={t('Set up your hotel')} />
            <div className="flex max-w-4xl flex-col gap-4 p-4">
                <Heading
                    title={t('Set up your hotel')}
                    description={t(
                        ':done of :total steps done. Each step saves as you go; you can come back any time.',
                        { done: summary.done, total: summary.total },
                    )}
                />
                <div className="h-2 overflow-hidden rounded bg-muted">
                    <div
                        className="h-full bg-primary"
                        style={{
                            width: `${(100 * summary.done) / summary.total}%`,
                        }}
                    />
                </div>

                <ol className="flex flex-col gap-2">
                    {steps.map((step, i) => (
                        <li key={step.key} className="rounded-xl border">
                            <button
                                type="button"
                                className="flex w-full items-center gap-3 px-3 py-2 text-left"
                                onClick={() =>
                                    setOpen(open === step.key ? null : step.key)
                                }
                                aria-expanded={open === step.key}
                            >
                                <span
                                    className={
                                        step.done
                                            ? 'flex size-6 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground'
                                            : 'flex size-6 items-center justify-center rounded-full border text-xs'
                                    }
                                >
                                    {step.done ? '✓' : i + 1}
                                </span>
                                <span className="flex-1 font-medium">
                                    {step.title}
                                </span>
                                {step.skipped && (
                                    <Badge variant="outline">
                                        {t('Skipped')}
                                    </Badge>
                                )}
                                {step.optional && !step.done && (
                                    <span className="text-xs text-muted-foreground">
                                        {t('optional')}
                                    </span>
                                )}
                            </button>
                            {open === step.key && (
                                <div className="flex flex-col gap-3 border-t px-3 py-3 text-sm">
                                    <p className="text-muted-foreground">
                                        {step.description}
                                    </p>
                                    {step.key === 'knowledge' && (
                                        <Questionnaire
                                            questions={questionnaire.questions}
                                            answers={questionnaire.answers}
                                        />
                                    )}
                                    {step.key === 'golive' && (
                                        <GoLivePanel golive={golive} />
                                    )}
                                    {step.key === 'rooms' && (
                                        <>
                                            {categories.length > 0 && (
                                                <ul className="text-xs">
                                                    {categories.map((c) => (
                                                        <li key={c.id}>
                                                            {c.name} ({c.code})
                                                            ·{' '}
                                                            {t(':count rooms', {
                                                                count: c.rooms,
                                                            })}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                            {can.rooms && (
                                                <QuickRooms
                                                    suggestions={suggestions}
                                                    firstNumber={firstNumber}
                                                />
                                            )}
                                            {can.rooms &&
                                                categories.length > 0 && (
                                                    <details>
                                                        <summary className="cursor-pointer text-xs underline">
                                                            {t(
                                                                'Or import rooms from a file',
                                                            )}
                                                        </summary>
                                                        <Importer
                                                            kind="rooms"
                                                            columns={
                                                                columns.rooms
                                                            }
                                                        />
                                                    </details>
                                                )}
                                        </>
                                    )}
                                    {step.key === 'prices' &&
                                        can.bookings &&
                                        categories.length > 0 && (
                                            <details>
                                                <summary className="cursor-pointer text-xs underline">
                                                    {t(
                                                        'Bring your upcoming bookings from a file (optional)',
                                                    )}
                                                </summary>
                                                <Importer
                                                    kind="bookings"
                                                    columns={columns.bookings}
                                                />
                                            </details>
                                        )}
                                    <div className="flex flex-wrap gap-2">
                                        {step.key !== 'rooms' && (
                                            <Button asChild size="sm">
                                                <Link href={step.url}>
                                                    {t('Open')}
                                                </Link>
                                            </Button>
                                        )}
                                        {step.key === 'taxes' && !step.done && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        SetupController.confirmTaxes.url(),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('These taxes are right')}
                                            </Button>
                                        )}
                                        {!step.done && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.post(
                                                        SetupController.skip.url(
                                                            step.key,
                                                        ),
                                                        { skip: !step.skipped },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {step.skipped
                                                    ? t('Resume this step')
                                                    : t('Skip for now')}
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            )}
                        </li>
                    ))}
                </ol>
            </div>
        </>
    );
}

Setup.layout = {
    breadcrumbs: [{ title: 'Set up your hotel', href: SetupController.show() }],
};
