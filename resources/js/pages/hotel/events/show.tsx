import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import EventController from '@/actions/App/Domains/Events/Http/Controllers/EventController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type Option = { value: string; label: string };
type EventDetail = {
    id: string;
    reference: string;
    client_name: string;
    company: string | null;
    phone: string | null;
    email: string | null;
    type: string;
    type_label: string;
    date: string;
    space: string | null;
    block: string | null;
    people: number;
    status: string;
    status_label: string;
    option_until: string | null;
    waitlisted: boolean;
    holds: boolean;
    notes: string | null;
    owner: string | null;
    event_space_id: string | null;
    event_block_id: number | null;
    layout: string | null;
    lost_reason: string | null;
    source: string;
};
type Props = {
    event: EventDetail;
    clash: { reference: string; status: string; id: string } | null;
    spaces: { id: string; name: string; capacities: Record<string, number> }[];
    blocks: { id: number; name: string }[];
    types: Option[];
    layouts: Option[];
    can: { manage: boolean };
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function ShowEvent({
    event,
    clash,
    spaces,
    blocks,
    types,
    layouts,
    can,
}: Props) {
    const open = ['enquiry', 'quoted', 'option'].includes(event.status);
    const [optionUntil, setOptionUntil] = useState('');
    const form = useForm({
        client_name: event.client_name,
        company: event.company ?? '',
        phone: event.phone ?? '',
        email: event.email ?? '',
        type: event.type,
        date: event.date,
        event_block_id:
            event.event_block_id === null ? '' : String(event.event_block_id),
        event_space_id: event.event_space_id ?? '',
        layout: event.layout ?? '',
        people: event.people,
        notes: event.notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const space = spaces.find((s) => s.id === form.data.event_space_id);
    const post = (url: string, data: Record<string, string | null> = {}) =>
        router.post(url, data, { preserveScroll: true });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            event_block_id:
                d.event_block_id === '' ? null : Number(d.event_block_id),
            event_space_id: d.event_space_id || null,
            layout: d.layout || null,
        }));
        form.put(EventController.update.url(event.id), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={event.reference} />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Heading
                        title={`${event.reference} · ${event.company ?? event.client_name}`}
                        description={`${event.type_label} · ${event.date} · ${t(':people people', { people: event.people })}`}
                    />
                    <Badge>
                        {event.waitlisted
                            ? t('Waiting list')
                            : event.status_label}
                    </Badge>
                    {event.option_until &&
                        event.status === 'option' &&
                        !event.waitlisted && (
                            <Badge variant="secondary">
                                {t('Held until :date', {
                                    date: event.option_until,
                                })}
                            </Badge>
                        )}
                </div>

                {clash && (
                    <p className="rounded-lg border border-amber-400 px-3 py-2 text-sm">
                        {t(
                            'The space is held by :reference (:status) at that time.',
                            {
                                reference: clash.reference,
                                status: clash.status,
                            },
                        )}
                    </p>
                )}
                <InputError message={errors.event} />

                {can.manage && (
                    <div className="flex flex-wrap items-end gap-2 rounded-xl border p-3 text-sm">
                        {open && (
                            <>
                                <div className="grid gap-1">
                                    <Label htmlFor="opt-until">
                                        {t('Hold the space until')}
                                    </Label>
                                    <Input
                                        id="opt-until"
                                        type="date"
                                        value={optionUntil}
                                        onChange={(e) =>
                                            setOptionUntil(e.target.value)
                                        }
                                    />
                                </div>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        post(
                                            EventController.option.url(
                                                event.id,
                                            ),
                                            { until: optionUntil || null },
                                        )
                                    }
                                >
                                    {event.status === 'option'
                                        ? t('Change the option')
                                        : t('Put on option')}
                                </Button>
                                <Button
                                    onClick={() =>
                                        post(
                                            EventController.confirm.url(
                                                event.id,
                                            ),
                                        )
                                    }
                                >
                                    {t('Confirm the event')}
                                </Button>
                                <Button
                                    variant="ghost"
                                    className="text-destructive"
                                    onClick={() => {
                                        const reason = window.prompt(
                                            t(
                                                'Why was it lost? (price, date, chose another place…)',
                                            ),
                                        );
                                        if (reason !== null) {
                                            post(
                                                EventController.close.url(
                                                    event.id,
                                                ),
                                                { status: 'lost', reason },
                                            );
                                        }
                                    }}
                                >
                                    {t('Mark as lost')}
                                </Button>
                            </>
                        )}
                        {event.status === 'confirmed' && (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        post(
                                            EventController.close.url(event.id),
                                            { status: 'done' },
                                        )
                                    }
                                >
                                    {t('Mark as done')}
                                </Button>
                                <Button
                                    variant="ghost"
                                    className="text-destructive"
                                    onClick={() => {
                                        const reason = window.prompt(
                                            t('Reason for cancelling'),
                                        );
                                        if (reason) {
                                            post(
                                                EventController.close.url(
                                                    event.id,
                                                ),
                                                { status: 'cancelled', reason },
                                            );
                                        }
                                    }}
                                >
                                    {t('Cancel the event')}
                                </Button>
                            </>
                        )}
                    </div>
                )}

                <form
                    onSubmit={submit}
                    className="grid gap-3 rounded-xl border p-4 sm:grid-cols-4"
                >
                    <div className="grid gap-1 sm:col-span-2">
                        <Label htmlFor="ed-client">{t('Contact name')}</Label>
                        <Input
                            id="ed-client"
                            value={form.data.client_name}
                            onChange={(e) =>
                                form.setData('client_name', e.target.value)
                            }
                            disabled={!can.manage || !open}
                        />
                    </div>
                    <div className="grid gap-1 sm:col-span-2">
                        <Label htmlFor="ed-company">
                            {t('Company or family')}
                        </Label>
                        <Input
                            id="ed-company"
                            value={form.data.company}
                            onChange={(e) =>
                                form.setData('company', e.target.value)
                            }
                            disabled={!can.manage || !open}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ed-phone">{t('Phone')}</Label>
                        <Input
                            id="ed-phone"
                            value={form.data.phone}
                            onChange={(e) =>
                                form.setData('phone', e.target.value)
                            }
                            disabled={!can.manage || !open}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ed-email">{t('Email')}</Label>
                        <Input
                            id="ed-email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                            disabled={!can.manage || !open}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ed-type">{t('Type')}</Label>
                        <select
                            id="ed-type"
                            className={selectClass}
                            value={form.data.type}
                            onChange={(e) =>
                                form.setData('type', e.target.value)
                            }
                            disabled={!can.manage || !open}
                        >
                            {types.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ed-date">{t('Date')}</Label>
                        <Input
                            id="ed-date"
                            type="date"
                            value={form.data.date}
                            onChange={(e) =>
                                form.setData('date', e.target.value)
                            }
                            disabled={!can.manage || !open}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ed-block">{t('Time')}</Label>
                        <select
                            id="ed-block"
                            className={selectClass}
                            value={form.data.event_block_id}
                            onChange={(e) =>
                                form.setData('event_block_id', e.target.value)
                            }
                            disabled={!can.manage || !open}
                        >
                            <option value="">{t('Not decided')}</option>
                            {blocks.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ed-space">{t('Space')}</Label>
                        <select
                            id="ed-space"
                            className={selectClass}
                            value={form.data.event_space_id}
                            onChange={(e) =>
                                form.setData('event_space_id', e.target.value)
                            }
                            disabled={!can.manage || !open}
                        >
                            <option value="">{t('Not decided')}</option>
                            {spaces.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ed-layout">{t('Layout')}</Label>
                        <select
                            id="ed-layout"
                            className={selectClass}
                            value={form.data.layout}
                            onChange={(e) =>
                                form.setData('layout', e.target.value)
                            }
                            disabled={!can.manage || !open}
                        >
                            <option value="">{t('Not decided')}</option>
                            {layouts.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                    {space?.capacities[o.value]
                                        ? ` (${space.capacities[o.value]})`
                                        : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ed-people">{t('People')}</Label>
                        <Input
                            id="ed-people"
                            type="number"
                            min={1}
                            value={form.data.people}
                            onChange={(e) =>
                                form.setData('people', Number(e.target.value))
                            }
                            disabled={!can.manage || !open}
                        />
                    </div>
                    <div className="grid gap-1 sm:col-span-4">
                        <Label htmlFor="ed-notes">{t('Notes')}</Label>
                        <textarea
                            id="ed-notes"
                            className="min-h-16 rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                            disabled={!can.manage || !open}
                        />
                    </div>
                    {can.manage && open && (
                        <div>
                            <Button disabled={form.processing}>
                                {t('Save')}
                            </Button>
                        </div>
                    )}
                </form>
                {event.lost_reason && (
                    <p className="text-sm text-muted-foreground">
                        {t('Reason: :reason', { reason: event.lost_reason })}
                    </p>
                )}
            </div>
        </>
    );
}

ShowEvent.layout = {
    breadcrumbs: [{ title: 'Events', href: EventController.index() }],
};
