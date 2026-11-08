import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AuditLogController from '@/actions/App/Domains/Platform/Http/Controllers/AuditLogController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Filters = {
    hotel: string | null;
    user: string | null;
    event: string | null;
    from: string | null;
    to: string | null;
};
type Props = {
    filters: Filters;
    hotels: { id: string; name: string }[];
    entries: {
        id: number;
        at: string | null;
        event: string | null;
        description: string;
        hotel_id: string | null;
        by: string | null;
        ip: string | null;
        properties: unknown;
    }[];
    pagination: { current: number; last: number; total: number };
    canExport: boolean;
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function Audit({
    filters,
    hotels,
    entries,
    pagination,
    canExport,
}: Props) {
    const [f, setF] = useState<Filters>(filters);
    const query = (extra: Record<string, string | number> = {}) =>
        Object.fromEntries(
            Object.entries({ ...f, ...extra }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        );
    const hotelName = (id: string | null) =>
        hotels.find((h) => h.id === id)?.name ?? '';

    return (
        <>
            <Head title={t('Audit log')} />
            <div className="flex flex-col gap-4 p-4 text-sm">
                <Heading
                    title={t('Audit log')}
                    description={t(
                        'Who did what, and when, in every hotel and on the platform.',
                    )}
                />
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            AuditLogController.index.url({
                                query: query() as Record<string, string>,
                            }),
                        );
                    }}
                    className="flex flex-wrap items-end gap-2"
                >
                    <select
                        aria-label={t('Hotel')}
                        className={selectClass}
                        value={f.hotel ?? ''}
                        onChange={(e) =>
                            setF({ ...f, hotel: e.target.value || null })
                        }
                    >
                        <option value="">{t('All hotels')}</option>
                        {hotels.map((h) => (
                            <option key={h.id} value={h.id}>
                                {h.name}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label={t('User email')}
                        placeholder={t('User email')}
                        className="w-48"
                        value={f.user ?? ''}
                        onChange={(e) =>
                            setF({ ...f, user: e.target.value || null })
                        }
                    />
                    <Input
                        aria-label={t('Event')}
                        placeholder={t('Event (e.g. billing.)')}
                        className="w-44"
                        value={f.event ?? ''}
                        onChange={(e) =>
                            setF({ ...f, event: e.target.value || null })
                        }
                    />
                    <Input
                        aria-label={t('From')}
                        type="date"
                        className="w-40"
                        value={f.from ?? ''}
                        onChange={(e) =>
                            setF({ ...f, from: e.target.value || null })
                        }
                    />
                    <Input
                        aria-label={t('To')}
                        type="date"
                        className="w-40"
                        value={f.to ?? ''}
                        onChange={(e) =>
                            setF({ ...f, to: e.target.value || null })
                        }
                    />
                    <Button size="sm">{t('Search')}</Button>
                    {canExport && (
                        <a
                            href={AuditLogController.export.url({
                                query: query() as Record<string, string>,
                            })}
                            className="self-center underline"
                        >
                            {t('Export CSV')}
                        </a>
                    )}
                </form>
                <p className="text-muted-foreground">
                    {t(':total entries', { total: pagination.total })}
                </p>
                <div tabIndex={0} className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-max">
                        <thead className="text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="p-2">{t('When')}</th>
                                <th className="p-2">{t('Event')}</th>
                                <th className="p-2">{t('What')}</th>
                                <th className="p-2">{t('Hotel')}</th>
                                <th className="p-2">{t('By')}</th>
                                <th className="p-2">{t('Details')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.map((e) => (
                                <tr key={e.id} className="border-t align-top">
                                    <td className="p-2 whitespace-nowrap">
                                        {e.at}
                                    </td>
                                    <td className="p-2 font-mono text-xs">
                                        {e.event}
                                    </td>
                                    <td className="p-2">{e.description}</td>
                                    <td className="p-2">
                                        {hotelName(e.hotel_id)}
                                    </td>
                                    <td className="p-2">{e.by}</td>
                                    <td
                                        className="max-w-96 truncate p-2 font-mono text-xs"
                                        title={JSON.stringify(e.properties)}
                                    >
                                        {JSON.stringify(e.properties)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="flex gap-2">
                    {pagination.current > 1 && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.get(
                                    AuditLogController.index.url({
                                        query: query({
                                            page: pagination.current - 1,
                                        }) as Record<string, string>,
                                    }),
                                )
                            }
                        >
                            ‹
                        </Button>
                    )}
                    <span className="self-center">
                        {pagination.current} / {pagination.last}
                    </span>
                    {pagination.current < pagination.last && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.get(
                                    AuditLogController.index.url({
                                        query: query({
                                            page: pagination.current + 1,
                                        }) as Record<string, string>,
                                    }),
                                )
                            }
                        >
                            ›
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}

Audit.layout = {
    breadcrumbs: [{ title: 'Audit log', href: AuditLogController.index() }],
};
