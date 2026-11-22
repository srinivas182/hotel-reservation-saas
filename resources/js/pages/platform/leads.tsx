import { Head, Link, router, useForm } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import PlatformDemoController from '@/actions/App/Domains/Demo/Http/Controllers/PlatformDemoController';
import { ProposalForm } from '@/components/sales/proposal-form';
import type { Plan, RoiInputs } from '@/components/sales/proposal-form';
import PlatformSiteController from '@/actions/App/Domains/Site/Http/Controllers/PlatformSiteController';
import {
    DataTable,
    Field,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

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
    demo_sent: string | null;
    demo_opened: string | null;
    proposals: {
        id: number;
        url: string;
        created: string | null;
        viewed: string | null;
        accepted: string | null;
    }[];
};
type Props = {
    filter: string | null;
    statuses: string[];
    counts: Record<string, number>;
    owners: { id: string; name: string }[];
    leads: Lead[];
    demoLink: string | null;
    proposalLink: string | null;
    plans: Plan[];
    roiDefaults: RoiInputs;
};

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
            className="grid gap-4"
        >
            <div className="grid gap-4 sm:grid-cols-3">
                <Field label={t('Status')}>
                    <select
                        className="h-9"
                        value={form.data.status}
                        onChange={(e) => form.setData('status', e.target.value)}
                    >
                        {statuses.map((s) => (
                            <option key={s} value={s}>
                                {statusLabel(s)}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label={t('Sales contact')}>
                    <select
                        className="h-9"
                        value={form.data.owner_id}
                        onChange={(e) =>
                            form.setData('owner_id', e.target.value)
                        }
                    >
                        <option value="">{t('Nobody')}</option>
                        {owners.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label={t('Follow up on')}>
                    <Input
                        type="date"
                        value={form.data.follow_up_on}
                        onChange={(e) =>
                            form.setData('follow_up_on', e.target.value)
                        }
                    />
                </Field>
            </div>
            {form.data.status === 'lost' && (
                <Field label={t('Why lost')} error={errors.lost_reason}>
                    <Input
                        value={form.data.lost_reason}
                        onChange={(e) =>
                            form.setData('lost_reason', e.target.value)
                        }
                    />
                </Field>
            )}
            <Field label={t('Notes')}>
                <textarea
                    className="min-h-20"
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
            </Field>
            <div className="flex flex-wrap justify-end gap-2">
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
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            PlatformDemoController.forLead.url(lead.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    {lead.demo_sent
                        ? t('Send a new demo link')
                        : t('Send a demo link')}
                </Button>
                <Button size="sm" disabled={form.processing}>
                    {t('Save')}
                </Button>
            </div>
        </form>
    );
}

function LinkCallout({ text, link }: { text: string; link: string }) {
    return (
        <div
            role="status"
            className="flex flex-wrap items-center gap-3 rounded-xl border border-brand/30 bg-brand/5 px-4 py-3 text-sm"
        >
            <span className="font-medium">{text}</span>
            <code className="min-w-0 flex-1 text-xs break-all">{link}</code>
            <Button
                size="sm"
                variant="outline"
                onClick={() => void navigator.clipboard?.writeText(link)}
            >
                <Copy /> {t('Copy')}
            </Button>
        </div>
    );
}

function LeadPanel({
    lead,
    statuses,
    owners,
    plans,
    roiDefaults,
    onClose,
}: {
    lead: Lead | undefined;
    statuses: string[];
    owners: Props['owners'];
    plans: Plan[];
    roiDefaults: RoiInputs;
    onClose: () => void;
}) {
    return (
        <SidePanel
            open={lead !== undefined}
            onClose={onClose}
            title={lead?.hotel_name ?? ''}
            description={
                lead
                    ? [
                          [lead.city, lead.country].filter(Boolean).join(', '),
                          lead.rooms
                              ? t(':rooms rooms', { rooms: lead.rooms })
                              : null,
                      ]
                          .filter(Boolean)
                          .join(' · ')
                    : undefined
            }
        >
            {lead && (
                <div className="grid gap-6">
                    <FieldGroup title={t('Contact')}>
                        <dl className="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">
                                {t('Name')}
                            </dt>
                            <dd>{lead.name}</dd>
                            <dt className="text-muted-foreground">WhatsApp</dt>
                            <dd className="tabular-nums">{lead.whatsapp}</dd>
                            <dt className="text-muted-foreground">
                                {t('Email')}
                            </dt>
                            <dd>{lead.email ?? '—'}</dd>
                            <dt className="text-muted-foreground">
                                {t('Best time to call')}
                            </dt>
                            <dd>{lead.contact_time ?? '—'}</dd>
                            <dt className="text-muted-foreground">
                                {t('Language')}
                            </dt>
                            <dd>{lead.locale.toUpperCase()}</dd>
                            {lead.hotel_id && (
                                <>
                                    <dt className="text-muted-foreground">
                                        {t('Hotel')}
                                    </dt>
                                    <dd>
                                        <Link
                                            href={HotelOverviewController.show(
                                                lead.hotel_id,
                                            )}
                                            className="text-brand hover:underline"
                                        >
                                            {t('Open the hotel')}
                                        </Link>
                                    </dd>
                                </>
                            )}
                        </dl>
                        {lead.message && (
                            <p className="rounded-lg bg-muted/50 p-3 text-sm whitespace-pre-wrap">
                                {lead.message}
                            </p>
                        )}
                    </FieldGroup>
                    <FieldGroup title={t('Follow-up')}>
                        <LeadEditor
                            key={lead.id}
                            lead={lead}
                            statuses={statuses}
                            owners={owners}
                        />
                    </FieldGroup>
                    <FieldGroup title={t('Return on investment and proposal')}>
                        {lead.proposals.length > 0 && (
                            <ul className="divide-y rounded-lg border text-sm">
                                {lead.proposals.map((p) => (
                                    <li
                                        key={p.id}
                                        className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                    >
                                        <a
                                            href={p.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-brand hover:underline"
                                        >
                                            {t('Proposal of :date', {
                                                date: p.created ?? '',
                                            })}
                                        </a>
                                        <span className="text-xs text-muted-foreground">
                                            {p.accepted
                                                ? t('trial started :date', {
                                                      date: p.accepted,
                                                  })
                                                : p.viewed
                                                  ? t('viewed :date', {
                                                        date: p.viewed,
                                                    })
                                                  : t('not viewed yet')}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <ProposalForm
                            leadId={lead.id}
                            plans={plans}
                            defaults={roiDefaults}
                            hasEmail={lead.email !== null}
                            rooms={lead.rooms}
                        />
                    </FieldGroup>
                </div>
            )}
        </SidePanel>
    );
}

export default function Leads({
    filter,
    statuses,
    counts,
    owners,
    leads,
    demoLink,
    proposalLink,
    plans,
    roiDefaults,
}: Props) {
    const [open, setOpen] = useState<number | null>(null);
    const total = Object.values(counts).reduce((a, b) => a + b, 0);
    const go = (status: string | null) =>
        router.get(
            PlatformSiteController.leads.url(
                status ? { query: { status } } : undefined,
            ),
        );

    return (
        <>
            <Head title={t('Leads')} />
            <PageBody>
                <PageHeader
                    title={t('Leads')}
                    description={t(
                        'Demo requests from the website, followed by the sales team.',
                    )}
                />

                <div
                    role="group"
                    aria-label={t('Pipeline')}
                    className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7"
                >
                    {[null, ...statuses].map((s) => {
                        const active = filter === s;

                        return (
                            <button
                                key={s ?? 'all'}
                                type="button"
                                aria-pressed={active}
                                onClick={() => go(s)}
                                className={cn(
                                    'rounded-xl border bg-card px-4 py-3 text-left transition-colors',
                                    active
                                        ? 'border-brand ring-1 ring-brand'
                                        : 'hover:bg-muted/50',
                                )}
                            >
                                <div className="text-xs text-muted-foreground">
                                    {s === null ? t('All') : statusLabel(s)}
                                </div>
                                <div className="mt-1 font-display text-2xl font-extrabold tabular-nums">
                                    {s === null ? total : (counts[s] ?? 0)}
                                </div>
                            </button>
                        );
                    })}
                </div>

                {proposalLink && (
                    <LinkCallout
                        text={t('Proposal link (valid 30 days):')}
                        link={proposalLink}
                    />
                )}
                {demoLink && (
                    <LinkCallout
                        text={t(
                            'Private demo link (48 h), also sent by email when we have one:',
                        )}
                        link={demoLink}
                    />
                )}

                <Section
                    flush
                    title={
                        filter === null ? t('All leads') : statusLabel(filter)
                    }
                    description={t('Click a lead to follow it up.')}
                >
                    <DataTable
                        rows={leads}
                        rowKey={(l) => l.id}
                        empty={t('No leads yet.')}
                        onRowClick={(l) => setOpen(l.id)}
                        columns={[
                            {
                                key: 'hotel',
                                header: t('Hotel'),
                                cell: (l) => (
                                    <div>
                                        <div className="font-medium">
                                            {l.hotel_name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {[l.city, l.country]
                                                .filter(Boolean)
                                                .join(', ')}
                                            {l.rooms
                                                ? ` · ${t(':rooms rooms', { rooms: l.rooms })}`
                                                : ''}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'contact',
                                header: t('Contact'),
                                cell: (l) => (
                                    <div>
                                        <div>{l.name}</div>
                                        <div className="text-xs text-muted-foreground tabular-nums">
                                            {l.whatsapp}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (l) => (
                                    <div className="flex flex-wrap gap-1">
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
                                        {l.demo_sent && (
                                            <Badge variant="outline">
                                                {l.demo_opened
                                                    ? t('Demo opened :date', {
                                                          date: l.demo_opened,
                                                      })
                                                    : t(
                                                          'Demo sent, not opened',
                                                      )}
                                            </Badge>
                                        )}
                                        {l.code && (
                                            <Badge variant="outline">
                                                {t('Code :code', {
                                                    code: l.code,
                                                })}
                                            </Badge>
                                        )}
                                    </div>
                                ),
                            },
                            {
                                key: 'owner',
                                header: t('Sales contact'),
                                cell: (l) =>
                                    l.owner ?? (
                                        <span className="text-muted-foreground">
                                            {t('Nobody')}
                                        </span>
                                    ),
                            },
                            {
                                key: 'created',
                                header: t('Received'),
                                cell: (l) => (
                                    <span className="text-muted-foreground">
                                        {l.created}
                                    </span>
                                ),
                            },
                        ]}
                    />
                </Section>
            </PageBody>

            <LeadPanel
                lead={leads.find((l) => l.id === open)}
                statuses={statuses}
                owners={owners}
                plans={plans}
                roiDefaults={roiDefaults}
                onClose={() => setOpen(null)}
            />
        </>
    );
}

Leads.layout = {
    breadcrumbs: [{ title: 'Leads', href: PlatformSiteController.leads() }],
};
