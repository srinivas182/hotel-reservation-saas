import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import PlatformSiteController from '@/actions/App/Domains/Site/Http/Controllers/PlatformSiteController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Lead = {
    id: number;
    name: string;
    hotel_name: string;
    country: string | null;
    city: string | null;
    rooms: number | null;
    whatsapp: string;
    email: string | null;
    message: string | null;
    contact_time: string | null;
    locale: string;
    status: string;
    lost_reason: string | null;
    owner_id: string | null;
    owner: string | null;
    notes: string | null;
    follow_up_on: string | null;
    overdue: boolean;
    code: string | null;
    hotel_id: string | null;
    created: string | null;
};
type Props = {
    filter: string | null;
    statuses: string[];
    counts: Record<string, number>;
    owners: { id: string; name: string }[];
    leads: Lead[];
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const statusLabel = (s: string) =>
    ({
        new: t('New'),
        contacted: t('Contacted'),
        demo_done: t('Demo done'),
        trial: t('Trial'),
        won: t('Won'),
        lost: t('Lost'),
    })[s] ?? s;

function LeadEditor({
    lead,
    statuses,
    owners,
}: {
    lead: Lead;
    statuses: string[];
    owners: Props['owners'];
}) {
    const form = useForm({
        status: lead.status,
        lost_reason: lead.lost_reason ?? '',
        owner_id: lead.owner_id ?? '',
        follow_up_on: lead.follow_up_on ?? '',
        notes: lead.notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    owner_id: d.owner_id || null,
                    follow_up_on: d.follow_up_on || null,
                    lost_reason: d.lost_reason || null,
                }));
                form.put(PlatformSiteController.updateLead.url(lead.id), {
                    preserveScroll: true,
                });
            }}
            className="grid gap-2 border-t pt-2 sm:grid-cols-4"
        >
            <select
                aria-label={t('Status')}
                className={selectClass}
                value={form.data.status}
                onChange={(e) => form.setData('status', e.target.value)}
            >
                {statuses.map((s) => (
                    <option key={s} value={s}>
                        {statusLabel(s)}
                    </option>
                ))}
            </select>
            <select
                aria-label={t('Sales contact')}
                className={selectClass}
                value={form.data.owner_id}
                onChange={(e) => form.setData('owner_id', e.target.value)}
            >
                <option value="">{t('Nobody')}</option>
                {owners.map((o) => (
                    <option key={o.id} value={o.id}>
                        {o.name}
                    </option>
                ))}
            </select>
            <Input
                aria-label={t('Follow up on')}
                type="date"
                value={form.data.follow_up_on}
                onChange={(e) => form.setData('follow_up_on', e.target.value)}
            />
            {form.data.status === 'lost' && (
                <Input
                    aria-label={t('Why lost')}
                    placeholder={t('Why lost')}
                    value={form.data.lost_reason}
                    onChange={(e) =>
                        form.setData('lost_reason', e.target.value)
                    }
                />
            )}
            <textarea
                aria-label={t('Notes')}
                placeholder={t('Notes')}
                className="min-h-14 rounded-md border border-input bg-transparent px-3 py-2 sm:col-span-4"
                value={form.data.notes}
                onChange={(e) => form.setData('notes', e.target.value)}
            />
            <InputError message={errors.lost_reason} />
            <div className="flex gap-2 sm:col-span-4">
                <Button size="sm" disabled={form.processing}>
                    {t('Save')}
                </Button>
                {!lead.code && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                PlatformSiteController.convertLead.url(lead.id),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('Create an invitation code')}
                    </Button>
                )}
            </div>
        </form>
    );
}

export default function Leads({
    filter,
    statuses,
    counts,
    owners,
    leads,
}: Props) {
    const [open, setOpen] = useState<number | null>(null);

    return (
        <>
            <Head title={t('Leads')} />
            <div className="flex max-w-6xl flex-col gap-4 p-4 text-sm">
                <Heading
                    title={t('Leads')}
                    description={t(
                        'Demo requests from the website, followed by the sales team.',
                    )}
                />
                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant={filter === null ? 'default' : 'outline'}
                        onClick={() =>
                            router.get(PlatformSiteController.leads.url())
                        }
                    >
                        {t('All')}
                    </Button>
                    {statuses.map((s) => (
                        <Button
                            key={s}
                            size="sm"
                            variant={filter === s ? 'default' : 'outline'}
                            onClick={() =>
                                router.get(
                                    PlatformSiteController.leads.url({
                                        query: { status: s },
                                    }),
                                )
                            }
                        >
                            {statusLabel(s)} ({counts[s] ?? 0})
                        </Button>
                    ))}
                </div>
                {leads.length === 0 && (
                    <p className="text-muted-foreground">
                        {t('No leads yet.')}
                    </p>
                )}
                <ul className="divide-y rounded-xl border">
                    {leads.map((l) => (
                        <li
                            key={l.id}
                            className="flex flex-col gap-2 px-3 py-2"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    className="font-medium underline"
                                    onClick={() =>
                                        setOpen(open === l.id ? null : l.id)
                                    }
                                    aria-expanded={open === l.id}
                                >
                                    {l.hotel_name}
                                </button>
                                <span className="text-muted-foreground">
                                    {[l.city, l.country]
                                        .filter(Boolean)
                                        .join(', ')}{' '}
                                    {l.rooms
                                        ? `· ${t(':rooms rooms', { rooms: l.rooms })}`
                                        : ''}
                                </span>
                                <span>
                                    {l.name} · {l.whatsapp}
                                </span>
                                <Badge
                                    variant={
                                        l.status === 'won'
                                            ? 'default'
                                            : l.status === 'lost'
                                              ? 'outline'
                                              : 'secondary'
                                    }
                                >
                                    {statusLabel(l.status)}
                                </Badge>
                                {l.overdue && (
                                    <Badge variant="destructive">
                                        {t('Follow-up due :date', {
                                            date: l.follow_up_on ?? '',
                                        })}
                                    </Badge>
                                )}
                                {l.code && (
                                    <Badge variant="outline">
                                        {t('Code :code', { code: l.code })}
                                    </Badge>
                                )}
                                {l.hotel_id && (
                                    <Link
                                        href={HotelOverviewController.show(
                                            l.hotel_id,
                                        )}
                                        className="underline"
                                    >
                                        {t('Hotel')}
                                    </Link>
                                )}
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {l.owner ?? t('Nobody')} · {l.created}
                                </span>
                            </div>
                            {open === l.id && (
                                <>
                                    <p className="text-muted-foreground">
                                        {[
                                            l.email,
                                            l.contact_time &&
                                                t('Call: :time', {
                                                    time: l.contact_time,
                                                }),
                                            l.locale.toUpperCase(),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    {l.message && (
                                        <p className="whitespace-pre-wrap">
                                            {l.message}
                                        </p>
                                    )}
                                    <LeadEditor
                                        lead={l}
                                        statuses={statuses}
                                        owners={owners}
                                    />
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Leads.layout = {
    breadcrumbs: [{ title: 'Leads', href: PlatformSiteController.leads() }],
};
