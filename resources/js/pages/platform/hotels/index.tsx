import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import HotelController from '@/actions/App/Domains/Platform/Http/Controllers/HotelController';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/platform/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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

    return (
        <>
            <Head title={t('Hotels')} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('Hotels')}
                        description={`${hotels.total} hotels on the platform`}
                    />
                    {canCreate && (
                        <Button asChild>
                            <Link href={HotelController.create()}>
                                <Plus /> {t('New hotel')}
                            </Link>
                        </Button>
                    )}
                </div>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            HotelController.index.url({ query: { search } }),
                            {},
                            { preserveState: true },
                        );
                    }}
                    className="max-w-sm"
                >
                    <Input
                        type="search"
                        placeholder={t('Search by name or slug')}
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        aria-label={t('Search hotels')}
                    />
                </form>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    {t('Hotel')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Status')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Country')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Owner')}
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    {t('Staff')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {hotels.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        {t('No hotels found.')}
                                    </td>
                                </tr>
                            )}
                            {hotels.data.map((hotel) => (
                                <tr key={hotel.id} className="border-t">
                                    <td className="px-4 py-3">
                                        <Link
                                            href={HotelController.edit(
                                                hotel.id,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {hotel.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {hotel.slug}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusBadge status={hotel.status} />
                                    </td>
                                    <td className="px-4 py-3">
                                        {hotel.country_code} · {hotel.currency}
                                    </td>
                                    <td className="px-4 py-3">
                                        {hotel.owner ? (
                                            hotel.owner.name
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {t('None')}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {hotel.members_count}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {hotels.links.length > 3 && (
                    <nav
                        className="flex flex-wrap gap-1"
                        aria-label={t('Pagination')}
                    >
                        {hotels.links.map((link, index) => (
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

HotelsIndex.layout = {
    breadcrumbs: [{ title: 'Hotels', href: HotelController.index() }],
};
