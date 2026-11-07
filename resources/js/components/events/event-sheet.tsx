import { useForm } from '@inertiajs/react';
import EventSheetController from '@/actions/App/Domains/Events/Http/Controllers/EventSheetController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Content = {
    timings?: { time: string; what: string }[];
    menu?: string | null;
    equipment?: string | null;
    kitchen?: string | null;
    setup?: string | null;
    reception?: string | null;
};
export type Sheet = {
    content: Content | null;
    version: number | null;
    changes_after_confirmation: number;
    history: {
        version: number;
        by: string | null;
        at: string | null;
        after_confirmation: boolean;
    }[];
    print_url: string;
};

export function EventSheetEditor({
    eventId,
    sheet,
    canManage,
}: {
    eventId: string;
    sheet: Sheet;
    canManage: boolean;
}) {
    const c = sheet.content ?? {};
    const form = useForm({
        timings: c.timings ?? [
            { time: '08:30', what: '' },
            { time: '10:30', what: '' },
            { time: '12:30', what: '' },
        ],
        menu: c.menu ?? '',
        equipment: c.equipment ?? '',
        kitchen: c.kitchen ?? '',
        setup: c.setup ?? '',
        reception: c.reception ?? '',
    });
    const areas: [
        keyof Content &
            ('menu' | 'equipment' | 'kitchen' | 'setup' | 'reception'),
        string,
    ][] = [
        ['menu', t('Menu')],
        ['equipment', t('Equipment')],
        ['kitchen', t('Kitchen')],
        ['setup', t('Setup')],
        ['reception', t('Reception')],
    ];

    return (
        <section className="flex flex-col gap-2 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Heading variant="small" title={t('Event sheet')} />
                {sheet.version && (
                    <Badge variant="outline">V{sheet.version}</Badge>
                )}
                {sheet.changes_after_confirmation > 0 && (
                    <Badge variant="destructive">
                        {t(':count change(s) after confirmation', {
                            count: sheet.changes_after_confirmation,
                        })}
                    </Badge>
                )}
                <a
                    href={sheet.print_url}
                    target="_blank"
                    rel="noreferrer"
                    className="ml-auto underline"
                >
                    {t('Print the event sheet')}
                </a>
            </div>
            {canManage && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            ...d,
                            timings: d.timings.filter(
                                (x) => x.what.trim() !== '',
                            ),
                        }));
                        form.post(EventSheetController.save.url(eventId), {
                            preserveScroll: true,
                        });
                    }}
                    className="flex flex-col gap-2 rounded-xl border p-3"
                >
                    <span className="font-medium">{t('Timings')}</span>
                    {form.data.timings.map((tm, i) => (
                        <div key={i} className="flex gap-2">
                            <Input
                                type="time"
                                aria-label={t('Time')}
                                className="w-28"
                                value={tm.time}
                                onChange={(e) =>
                                    form.setData(
                                        'timings',
                                        form.data.timings.map((x, j) =>
                                            j === i
                                                ? { ...x, time: e.target.value }
                                                : x,
                                        ),
                                    )
                                }
                            />
                            <Input
                                aria-label={t('What happens')}
                                placeholder={t('e.g. Coffee break')}
                                value={tm.what}
                                onChange={(e) =>
                                    form.setData(
                                        'timings',
                                        form.data.timings.map((x, j) =>
                                            j === i
                                                ? { ...x, what: e.target.value }
                                                : x,
                                        ),
                                    )
                                }
                            />
                        </div>
                    ))}
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                form.setData('timings', [
                                    ...form.data.timings,
                                    { time: '14:00', what: '' },
                                ])
                            }
                        >
                            {t('Add a time')}
                        </Button>
                    </div>
                    {areas.map(([key, label]) => (
                        <label key={key} className="grid gap-1">
                            <span className="font-medium">{label}</span>
                            <textarea
                                className="min-h-14 rounded-md border border-input bg-transparent px-3 py-2"
                                value={form.data[key]}
                                onChange={(e) =>
                                    form.setData(key, e.target.value)
                                }
                            />
                        </label>
                    ))}
                    <div>
                        <Button disabled={form.processing}>
                            {t('Save a new version')}
                        </Button>
                    </div>
                </form>
            )}
            {sheet.history.length > 0 && (
                <ul className="text-xs text-muted-foreground">
                    {sheet.history.map((h) => (
                        <li key={h.version}>
                            V{h.version} · {h.at} · {h.by}
                            {h.after_confirmation &&
                                ` · ${t('after confirmation')}`}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
