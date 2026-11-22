import { Head, router, useForm } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useState } from 'react';
import ReadinessController from '@/actions/App/Domains/Platform/Http/Controllers/ReadinessController';
import {
    DataTable,
    Field,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    StatTile,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type HotelRow = {
    id: string;
    name: string;
    mode: string;
    overridden: boolean;
    override_reason: string | null;
    missing: string[];
    recommended_missing: number;
};

function OverrideForm({
    hotel,
    onDone,
}: {
    hotel: HotelRow;
    onDone: () => void;
}) {
    const form = useForm({ reason: '' });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(ReadinessController.override.url(hotel.id), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="grid gap-4"
        >
            <FieldGroup title={t('Still missing')}>
                <ul className="grid gap-2 text-sm">
                    {hotel.missing.map((m) => (
                        <li key={m} className="flex items-start gap-2">
                            <CircleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
                            {m}
                        </li>
                    ))}
                </ul>
            </FieldGroup>
            <Field
                label={t('Reason')}
                hint={t(
                    'Kept in the audit log. Use it only for a pilot or a known exception.',
                )}
                error={form.errors.reason}
            >
                <textarea
                    className="min-h-20"
                    placeholder={t('Why go live without every required check?')}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
            </Field>
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button variant="destructive" disabled={form.processing}>
                    {t('Allow go-live')}
                </Button>
            </div>
        </form>
    );
}

export default function PlatformReadiness({ hotels }: { hotels: HotelRow[] }) {
    const [overriding, setOverriding] = useState<HotelRow | null>(null);
    const ready = hotels.filter((h) => h.missing.length === 0).length;

    return (
        <>
            <Head title={t('Go-live readiness')} />
            <PageBody>
                <PageHeader
                    title={t('Go-live readiness')}
                    description={t(
                        'Required checks still missing for each hotel.',
                    )}
                />
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatTile label={t('Hotels')} value={hotels.length} />
                    <StatTile label={t('Ready')} value={ready} />
                    <StatTile
                        label={t('Not ready')}
                        value={hotels.length - ready}
                    />
                    <StatTile
                        label={t('Allowed by override')}
                        value={hotels.filter((h) => h.overridden).length}
                    />
                </div>
                <Section flush title={t('Hotels')}>
                    <DataTable
                        rows={hotels}
                        rowKey={(h) => h.id}
                        empty={t('No hotels found.')}
                        columns={[
                            {
                                key: 'hotel',
                                header: t('Hotel'),
                                cell: (h) => (
                                    <div>
                                        <div className="font-medium">
                                            {h.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {h.mode}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (h) => (
                                    <div className="flex flex-wrap gap-1">
                                        <Badge
                                            variant={
                                                h.missing.length === 0
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                        >
                                            {h.missing.length === 0
                                                ? t('Ready')
                                                : t(':count missing', {
                                                      count: h.missing.length,
                                                  })}
                                        </Badge>
                                        {h.overridden && (
                                            <Badge
                                                variant="outline"
                                                title={
                                                    h.override_reason ??
                                                    undefined
                                                }
                                            >
                                                {t('Allowed by override')}
                                            </Badge>
                                        )}
                                    </div>
                                ),
                            },
                            {
                                key: 'missing',
                                header: t('Still missing'),
                                cell: (h) =>
                                    h.missing.length === 0 ? (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    ) : (
                                        <span
                                            className="line-clamp-2 text-xs text-muted-foreground"
                                            title={h.missing.join(' · ')}
                                        >
                                            {h.missing.join(' · ')}
                                        </span>
                                    ),
                            },
                            {
                                key: 'actions',
                                header: '',
                                align: 'right',
                                cell: (h) =>
                                    h.overridden ? (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    ReadinessController.override.url(
                                                        h.id,
                                                    ),
                                                    { remove: true },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Remove override')}
                                        </Button>
                                    ) : (
                                        h.missing.length > 0 && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setOverriding(h)}
                                            >
                                                {t('Override')}
                                            </Button>
                                        )
                                    ),
                            },
                        ]}
                    />
                </Section>
            </PageBody>
            <SidePanel
                open={overriding !== null}
                onClose={() => setOverriding(null)}
                title={
                    overriding
                        ? t('Allow :hotel to go live', {
                              hotel: overriding.name,
                          })
                        : ''
                }
            >
                {overriding && (
                    <OverrideForm
                        key={overriding.id}
                        hotel={overriding}
                        onDone={() => setOverriding(null)}
                    />
                )}
            </SidePanel>
        </>
    );
}

PlatformReadiness.layout = {
    breadcrumbs: [
        { title: 'Go-live readiness', href: ReadinessController.index() },
    ],
};
