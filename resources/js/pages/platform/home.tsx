import { Head, Link, router } from '@inertiajs/react';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import PlatformHomeController from '@/actions/App/Domains/Platform/Http/Controllers/PlatformHomeController';
import Heading from '@/components/heading';
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

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function Tile({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="min-w-32 rounded-xl border px-4 py-3">
            <div className="text-xl font-semibold">{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}

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
            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title={t('Platform home')}
                        description={t(
                            'What needs attention, today, and the business.',
                        )}
                    />
                    <div className="flex gap-2">
                        <select
                            aria-label={t('Country')}
                            className={selectClass}
                            value={filters.country ?? ''}
                            onChange={(e) => filter('country', e.target.value)}
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
                            className={selectClass}
                            value={filters.entity ?? ''}
                            onChange={(e) => filter('entity', e.target.value)}
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
                    </div>
                </div>

                <section className="flex flex-col gap-2 text-sm">
                    <Heading variant="small" title={t('Needs attention')} />
                    {incidents.map((i) => (
                        <p
                            key={i.id}
                            className="rounded-lg border border-destructive px-3 py-2 text-destructive"
                        >
                            {i.title} · {t('since :date', { date: i.since })}
                        </p>
                    ))}
                    {attention.length === 0 && incidents.length === 0 && (
                        <p className="text-muted-foreground">
                            {t('Nothing needs attention.')}
                        </p>
                    )}
                    <ul className="divide-y rounded-xl border">
                        {attention.map((a) => (
                            <li
                                key={a.hotel_id}
                                className="flex flex-wrap items-center gap-2 px-3 py-2"
                            >
                                <Link
                                    href={HotelOverviewController.show(
                                        a.hotel_id,
                                    )}
                                    className="font-medium underline"
                                >
                                    {a.hotel}
                                </Link>
                                <span className="text-muted-foreground">
                                    {a.country}
                                </span>
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
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Today')} />
                    <div className="flex flex-wrap gap-2">
                        <Tile
                            label={t('Guest messages')}
                            value={today.messages_in}
                        />
                        <Tile
                            label={t('Messages sent')}
                            value={today.messages_out}
                        />
                        <Tile label={t('Bookings')} value={today.bookings} />
                        <Tile label={t('AI calls')} value={today.ai_runs} />
                        <Tile
                            label={t('AI cost (US$)')}
                            value={today.ai_cost_usd}
                        />
                    </div>
                </section>

                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Business')} />
                    <div className="flex flex-wrap gap-2">
                        <Tile label={t('Hotels')} value={business.hotels} />
                        <Tile label={t('Live')} value={business.live} />
                        <Tile
                            label={t('Trials ending this week')}
                            value={business.trials_ending}
                        />
                        <Tile
                            label={t('Sign-ups this week')}
                            value={business.signups_week}
                        />
                        {Object.entries(business.mrr).map(([c, v]) => (
                            <Tile
                                key={c}
                                label={t('Monthly revenue :currency', {
                                    currency: c,
                                })}
                                value={v}
                            />
                        ))}
                        {Object.entries(business.overdue).map(([c, v]) => (
                            <Tile
                                key={`o${c}`}
                                label={t('Overdue :currency', { currency: c })}
                                value={v}
                            />
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}

PlatformHome.layout = {
    breadcrumbs: [{ title: 'Platform home', href: PlatformHomeController() }],
};
