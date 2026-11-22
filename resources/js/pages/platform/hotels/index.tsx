import { Head, Link, router } from '@inertiajs/react';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import { ChevronRight, Plus } from 'lucide-react';
import { useState } from 'react';
import HotelController from '@/actions/App/Domains/Platform/Http/Controllers/HotelController';
import {
    DataTable,
    PageBody,
    PageHeader,
    Pagination,
    SearchBox,
    Section,
} from '@/components/page';
import { StatusBadge } from '@/components/platform/status-badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type HotelRow = {
    id: string;
    name: string;
    slug: string;
    country_code: string;
    currency: string;
    status: string;
    owner: { id: string; name: string; email: string } | null;
    members_count: number;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

type Props = {
    hotels: Paginated<HotelRow>;
    filters: { search: string };
    canCreate: boolean;
};

export default function HotelsIndex({ hotels, filters, canCreate }: Props) {
    const [search, setSearch] = useState(filters.search);
    const live = hotels.data.filter((h) => h.status === 'active').length;

    return (
        <>
            <Head title={t('Hotels')} />
            <PageBody>
                <PageHeader
                    title={t('Hotels')}
                    description={t(':count hotels on the platform', {
                        count: hotels.total,
                    })}
                    actions={
                        canCreate && (
                            <Button asChild>
                                <Link href={HotelController.create()}>
                                    <Plus /> {t('New hotel')}
                                </Link>
                            </Button>
                        )
                    }
                />

                <Section
                    flush
                    title={t('All hotels')}
                    description={t(':count active on this page', {
                        count: live,
                    })}
                    actions={
                        <SearchBox
                            value={search}
                            onChange={setSearch}
                            onSubmit={() =>
                                router.get(
                                    HotelController.index.url({
                                        query: { search },
                                    }),
                                    {},
                                    { preserveState: true },
                                )
                            }
                            placeholder={t('Search by name or slug')}
                            label={t('Search hotels')}
                        />
                    }
                >
                    <DataTable
                        rows={hotels.data}
                        rowKey={(h) => h.id}
                        empty={t('No hotels found.')}
                        onRowClick={(h) =>
                            router.visit(HotelOverviewController.show(h.id))
                        }
                        columns={[
                            {
                                key: 'hotel',
                                header: t('Hotel'),
                                cell: (hotel) => (
                                    <div className="flex items-center gap-3">
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-sm font-semibold text-brand">
                                            {hotel.name.charAt(0).toUpperCase()}
                                        </span>
                                        <div className="min-w-0">
                                            <Link
                                                href={HotelOverviewController.show(
                                                    hotel.id,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {hotel.name}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {hotel.slug}
                                            </div>
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (hotel) => (
                                    <StatusBadge status={hotel.status} />
                                ),
                            },
                            {
                                key: 'country',
                                header: t('Country'),
                                cell: (hotel) =>
                                    `${hotel.country_code} · ${hotel.currency}`,
                            },
                            {
                                key: 'owner',
                                header: t('Owner'),
                                cell: (hotel) =>
                                    hotel.owner ? (
                                        <div>
                                            <div>{hotel.owner.name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {hotel.owner.email}
                                            </div>
                                        </div>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {t('None')}
                                        </span>
                                    ),
                            },
                            {
                                key: 'staff',
                                header: t('Staff'),
                                align: 'right',
                                cell: (hotel) => hotel.members_count,
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
                    <Pagination links={hotels.links} label={t('Pagination')} />
                </Section>
            </PageBody>
        </>
    );
}

HotelsIndex.layout = {
    breadcrumbs: [{ title: 'Hotels', href: HotelController.index() }],
};
