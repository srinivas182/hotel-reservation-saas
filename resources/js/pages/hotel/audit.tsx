import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import HotelAuditController from '@/actions/App/Domains/Audit/Http/Controllers/HotelAuditController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/hooks/use-format';
import { t } from '@/lib/i18n';

type Entry = {
    id: number;
    event: string | null;
    description: string;
    user: string | null;
    ip: string | null;
    properties: Record<string, unknown>;
    created_at: string | null;
};

type Props = {
    entries: {
        data: Entry[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    events: string[];
    people: { id: string; name: string }[];
    filters: { event: string; user: string; from: string; to: string };
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export default function HotelAudit({
    entries,
    events,
    people,
    filters,
}: Props) {
    const format = useFormat();
    const [values, setValues] = useState(filters);

    const apply = (event: FormEvent) => {
        event.preventDefault();
        router.get(HotelAuditController.url(), values, { preserveState: true });
    };

    return (
        <>
            <Head title={t('Audit log')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading
                    title={t('Audit log')}
                    description={t(
                        'A permanent record of sign-ins and changes in this hotel.',
                    )}
                />

                <form
                    onSubmit={apply}
                    className="grid gap-3 sm:grid-cols-5 sm:items-end"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="audit-event">{t('Event')}</Label>
                        <select
                            id="audit-event"
                            className={selectClass}
                            value={values.event}
                            onChange={(e) =>
                                setValues({ ...values, event: e.target.value })
                            }
                        >
                            <option value="">{t('All events')}</option>
                            {events.map((event) => (
                                <option key={event} value={event}>
                                    {event}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="audit-user">{t('Person')}</Label>
                        <select
                            id="audit-user"
                            className={selectClass}
                            value={values.user}
                            onChange={(e) =>
                                setValues({ ...values, user: e.target.value })
                            }
                        >
                            <option value="">{t('Everyone')}</option>
                            {people.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="audit-from">{t('From')}</Label>
                        <Input
                            id="audit-from"
                            type="date"
                            value={values.from}
                            onChange={(e) =>
                                setValues({ ...values, from: e.target.value })
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="audit-to">{t('To')}</Label>
                        <Input
                            id="audit-to"
                            type="date"
                            value={values.to}
                            onChange={(e) =>
                                setValues({ ...values, to: e.target.value })
                            }
                        />
                    </div>
                    <Button>{t('Filter')}</Button>
                </form>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    {t('When')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('What')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Who')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('IP address')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        {t(
                                            'Nothing recorded for these filters.',
                                        )}
                                    </td>
                                </tr>
                            )}
                            {entries.data.map((entry) => (
                                <tr
                                    key={entry.id}
                                    className="border-t align-top"
                                >
                                    <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                        {format.dateTime(entry.created_at)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="font-medium">
                                            {entry.description}
                                        </div>
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {entry.event}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {entry.user ?? t('System')}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                        {entry.ip ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {entries.links.length > 3 && (
                    <nav
                        className="flex flex-wrap gap-1"
                        aria-label={t('Pagination')}
                    >
                        {entries.links.map((link, index) => (
                            <Button
                                key={index}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={link.url === null}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveState: true },
                                    )
                                }
                            >
                                <span
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            </Button>
                        ))}
                    </nav>
                )}
            </div>
        </>
    );
}

HotelAudit.layout = {
    breadcrumbs: [{ title: 'Audit log', href: HotelAuditController() }],
};
