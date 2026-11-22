import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, TriangleAlert } from 'lucide-react';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import PlatformHomeController from '@/actions/App/Domains/Platform/Http/Controllers/PlatformHomeController';
import {
    DataTable,
    PageBody,
    PageHeader,
    Section,
    StatTile,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { t } from '@/lib/i18n';

type Props = {
    filters: { country: string | null; entity: number | null };
    countries: string[];
    entities: { id: number; name: string }[];
    attention: {
        hotel_id: string;
        hotel: string;
        country: string;
        issues: { key: string; label: string }[];
    }[];
    incidents: { id: number; title: string; since: string }[];
    today: {
        messages_in: number;
        messages_out: number;
        bookings: number;
        ai_runs: number;
        ai_cost_usd: string;
    };
    business: {
        hotels: number;
        live: number;
        trials_ending: number;
        signups_week: number;
        mrr: Record<string, string>;
        overdue: Record<string, string>;
    };
};

export default function PlatformHome({
    filters,
    countries,
    entities,
    attention,
    incidents,
    today,
    business,
}: Props) {
    const filter = (key: 'country' | 'entity', value: string) =>
        router.get(
            PlatformHomeController.url({
                query: { ...filters, [key]: value || undefined },
            }),
            {},
            { preserveState: true },
        );

    return (
        <>
            <Head title={t('Platform home')} />
            <PageBody>
                <PageHeader
                    title={t('Platform home')}
                    description={t(
                        'What needs attention, today, and the business.',
                    )}
                    actions={
                        <>
                            <select
                                aria-label={t('Country')}
                                className="h-9"
                                value={filters.country ?? ''}
                                onChange={(e) =>
                                    filter('country', e.target.value)
                                }
                            >
                                <option value="">{t('All countries')}</option>
                                {countries.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label={t('Issuing company')}
                                className="h-9"
                                value={filters.entity ?? ''}
                                onChange={(e) =>
                                    filter('entity', e.target.value)
                                }
                            >
                                <option value="">
                                    {t('All issuing companies')}
                                </option>
                                {entities.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.name}
                                    </option>
                                ))}
                            </select>
                        </>
                    }
                />

                {incidents.map((i) => (
                    <div
                        key={i.id}
                        role="alert"
                        className="flex items-center gap-3 rounded-xl border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                    >
                        <TriangleAlert className="size-4 shrink-0" />
                        <span className="font-medium">{i.title}</span>
                        <span className="text-destructive/80">
                            {t('since :date', { date: i.since })}
                        </span>
                    </div>
                ))}

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatTile
                        label={t('Hotels')}
                        value={business.hotels}
                        hint={t(':count live', { count: business.live })}
                    />
                    <StatTile
                        label={t('Sign-ups this week')}
                        value={business.signups_week}
                    />
                    <StatTile
                        label={t('Trials ending this week')}
                        value={business.trials_ending}
                    />
                    {Object.entries(business.mrr).map(([c, v]) => (
                        <StatTile
                            key={c}
                            label={t('Monthly revenue :currency', {
                                currency: c,
                            })}
                            value={v}
                        />
                    ))}
                    {Object.entries(business.overdue).map(([c, v]) => (
                        <StatTile
                            key={`o${c}`}
                            label={t('Overdue :currency', { currency: c })}
                            value={
                                <span className="text-destructive">{v}</span>
                            }
                        />
                    ))}
                </div>

                <Section
                    flush
                    title={t('Needs attention')}
                    description={t(
                        'Hotels with a problem or waiting for the platform team.',
                    )}
                >
                    <DataTable
                        rows={attention}
                        rowKey={(a) => a.hotel_id}
                        empty={t('Nothing needs attention.')}
                        onRowClick={(a) =>
                            router.visit(
                                HotelOverviewController.show(a.hotel_id),
                            )
                        }
                        columns={[
                            {
                                key: 'hotel',
                                header: t('Hotel'),
                                cell: (a) => (
                                    <Link
                                        href={HotelOverviewController.show(
                                            a.hotel_id,
                                        )}
                                        className="font-medium hover:underline"
                                    >
                                        {a.hotel}
                                    </Link>
                                ),
                            },
                            {
                                key: 'country',
                                header: t('Country'),
                                cell: (a) => a.country,
                            },
                            {
                                key: 'issues',
                                header: t('What is wrong'),
                                cell: (a) => (
                                    <div className="flex flex-wrap gap-1">
                                        {a.issues.map((i) => (
                                            <Badge
                                                key={i.key}
                                                variant={
                                                    i.key === 'golive_request'
                                                        ? 'secondary'
                                                        : 'destructive'
                                                }
                                            >
                                                {i.label}
                                            </Badge>
                                        ))}
                                    </div>
                                ),
                            },
                            {
                                key: 'open',
                                header: '',
                                align: 'right',
                                cell: () => (
                                    <ChevronRight className="ml-auto size-4 text-muted-foreground" />
                                ),
                            },
                        ]}
                    />
                </Section>

                <Section
                    title={t('Today')}
                    description={t(
                        'Activity across all hotels since midnight.',
                    )}
                >
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-5">
                        {[
                            [t('Guest messages'), today.messages_in],
                            [t('Messages sent'), today.messages_out],
                            [t('Bookings'), today.bookings],
                            [t('AI calls'), today.ai_runs],
                            [t('AI cost (US$)'), today.ai_cost_usd],
                        ].map(([label, value]) => (
                            <div key={String(label)}>
                                <dt className="text-xs text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="mt-1 text-xl font-semibold tabular-nums">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Section>
            </PageBody>
        </>
    );
}

PlatformHome.layout = {
    breadcrumbs: [{ title: 'Platform home', href: PlatformHomeController() }],
};
