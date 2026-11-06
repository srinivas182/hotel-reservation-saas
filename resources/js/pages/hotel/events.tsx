import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import EventController from '@/actions/App/Domains/Events/Http/Controllers/EventController';
import EventSetupController from '@/actions/App/Domains/Events/Http/Controllers/EventSetupController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Option = { value: string; label: string };
type Summary = {
    id: string;
    reference: string;
    client_name: string;
    company: string | null;
    type_label: string;
    date: string;
    space: string | null;
    block: string | null;
    people: number;
    status: string;
    status_label: string;
    option_until: string | null;
    waitlisted: boolean;
};
type CalendarEntry = {
    id: string;
    reference: string;
    day: number;
    space: string | null;
    block: string | null;
    client: string;
    status: string;
    waitlisted: boolean;
    live: boolean;
};
type Props = {
    month: string;
    days: number;
    spaces: { id: string; name: string }[];
    calendar: CalendarEntry[];
    open: Summary[];
    blocks: { id: number; name: string }[];
    types: Option[];
    layouts: Option[];
    can: { manage: boolean };
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const shiftMonth = (month: string, by: number) => {
    const d = new Date(`${month}-15T12:00:00`);
    d.setMonth(d.getMonth() + by);

    return d.toISOString().slice(0, 7);
};

function NewEnquiry({
    blocks,
    types,
    layouts,
    spaces,
    onDone,
}: {
    blocks: Props['blocks'];
    types: Option[];
    layouts: Option[];
    spaces: Props['spaces'];
    onDone: () => void;
}) {
    const form = useForm({
        client_name: '',
        company: '',
        phone: '',
        email: '',
        type: 'seminar',
        date: '',
        event_block_id: '',
        event_space_id: '',
        layout: '',
        people: 20,
        notes: '',
        source: 'phone',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            event_block_id:
                d.event_block_id === '' ? null : Number(d.event_block_id),
            event_space_id: d.event_space_id || null,
            layout: d.layout || null,
        }));
        form.post(EventController.store.url(), { onSuccess: onDone });
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-4"
        >
            <div className="grid gap-1 sm:col-span-2">
                <Label htmlFor="ev-client">{t('Contact name')}</Label>
                <Input
                    id="ev-client"
                    value={form.data.client_name}
                    onChange={(e) =>
                        form.setData('client_name', e.target.value)
                    }
                />
                <InputError message={errors.client_name} />
            </div>
            <div className="grid gap-1 sm:col-span-2">
                <Label htmlFor="ev-company">{t('Company or family')}</Label>
                <Input
                    id="ev-company"
                    value={form.data.company}
                    onChange={(e) => form.setData('company', e.target.value)}
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="ev-phone">{t('Phone')}</Label>
                <Input
                    id="ev-phone"
                    type="tel"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="ev-email">{t('Email')}</Label>
                <Input
                    id="ev-email"
                    type="email"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="ev-type">{t('Type')}</Label>
                <select
                    id="ev-type"
                    className={selectClass}
                    value={form.data.type}
                    onChange={(e) => form.setData('type', e.target.value)}
                >
                    {types.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-1">
                <Label htmlFor="ev-date">{t('Date')}</Label>
                <Input
                    id="ev-date"
                    type="date"
                    value={form.data.date}
                    onChange={(e) => form.setData('date', e.target.value)}
                />
                <InputError message={errors.date} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="ev-block">{t('Time')}</Label>
                <select
                    id="ev-block"
                    className={selectClass}
                    value={form.data.event_block_id}
                    onChange={(e) =>
                        form.setData('event_block_id', e.target.value)
                    }
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
                <Label htmlFor="ev-space">{t('Space')}</Label>
                <select
                    id="ev-space"
                    className={selectClass}
                    value={form.data.event_space_id}
                    onChange={(e) =>
                        form.setData('event_space_id', e.target.value)
                    }
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
                <Label htmlFor="ev-layout">{t('Layout')}</Label>
                <select
                    id="ev-layout"
                    className={selectClass}
                    value={form.data.layout}
                    onChange={(e) => form.setData('layout', e.target.value)}
                >
                    <option value="">{t('Not decided')}</option>
                    {layouts.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-1">
                <Label htmlFor="ev-people">{t('People')}</Label>
                <Input
                    id="ev-people"
                    type="number"
                    min={1}
                    value={form.data.people}
                    onChange={(e) =>
                        form.setData('people', Number(e.target.value))
                    }
                />
            </div>
            <div className="grid gap-1 sm:col-span-3">
                <Label htmlFor="ev-notes">{t('Notes')}</Label>
                <Input
                    id="ev-notes"
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
            </div>
            <div className="self-end">
                <Button disabled={form.processing}>{t('Save enquiry')}</Button>
            </div>
        </form>
    );
}

export default function Events({
    month,
    days,
    spaces,
    calendar,
    open,
    blocks,
    types,
    layouts,
    can,
}: Props) {
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title={t('Events')} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title={t('Events')}
                        description={t(
                            'Meeting rooms, seminars, weddings and private parties.',
                        )}
                    />
                    <div className="flex gap-2">
                        {can.manage && (
                            <Link
                                href={EventSetupController.index()}
                                className="self-center text-sm underline"
                            >
                                {t('Spaces, prices and packages')}
                            </Link>
                        )}
                        {can.manage && (
                            <Button onClick={() => setAdding(!adding)}>
                                {adding ? t('Close') : t('New enquiry')}
                            </Button>
                        )}
                    </div>
                </div>

                {adding && (
                    <NewEnquiry
                        blocks={blocks}
                        types={types}
                        layouts={layouts}
                        spaces={spaces}
                        onDone={() => setAdding(false)}
                    />
                )}

                <section className="flex flex-col gap-2">
                    <div className="flex items-center gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.get(
                                    EventController.index.url({
                                        query: { month: shiftMonth(month, -1) },
                                    }),
                                )
                            }
                        >
                            ‹
                        </Button>
                        <span className="font-medium">{month}</span>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.get(
                                    EventController.index.url({
                                        query: { month: shiftMonth(month, 1) },
                                    }),
                                )
                            }
                        >
                            ›
                        </Button>
                    </div>
                    {spaces.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('Add event spaces to see the calendar.')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-max text-xs">
                                <thead>
                                    <tr>
                                        <th className="sticky left-0 bg-background p-2 text-left">
                                            {t('Space')}
                                        </th>
                                        {Array.from(
                                            { length: days },
                                            (_, i) => (
                                                <th
                                                    key={i}
                                                    className="w-20 p-1 text-center font-normal text-muted-foreground"
                                                >
                                                    {i + 1}
                                                </th>
                                            ),
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {spaces.map((space) => (
                                        <tr key={space.id} className="border-t">
                                            <td className="sticky left-0 bg-background p-2 font-medium">
                                                {space.name}
                                            </td>
                                            {Array.from(
                                                { length: days },
                                                (_, i) => {
                                                    const entries =
                                                        calendar.filter(
                                                            (c) =>
                                                                c.space ===
                                                                    space.id &&
                                                                c.day === i + 1,
                                                        );

                                                    return (
                                                        <td
                                                            key={i}
                                                            className="p-0.5 align-top"
                                                        >
                                                            {entries.map(
                                                                (e) => (
                                                                    <Link
                                                                        key={
                                                                            e.id
                                                                        }
                                                                        href={EventController.show(
                                                                            e.id,
                                                                        )}
                                                                        className={cn(
                                                                            'mb-0.5 block truncate rounded px-1 py-0.5',
                                                                            e.waitlisted
                                                                                ? 'border border-dashed'
                                                                                : e.status ===
                                                                                        'confirmed' ||
                                                                                    e.status ===
                                                                                        'done'
                                                                                  ? 'bg-primary text-primary-foreground'
                                                                                  : 'bg-amber-200 text-amber-950',
                                                                        )}
                                                                        title={`${e.reference} · ${e.client} · ${e.block ?? ''}`}
                                                                    >
                                                                        {
                                                                            e.block
                                                                        }{' '}
                                                                        {
                                                                            e.client
                                                                        }
                                                                    </Link>
                                                                ),
                                                            )}
                                                        </td>
                                                    );
                                                },
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Dark: confirmed · Amber: on option · Dashed: waiting list',
                        )}
                    </p>
                </section>

                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('Open enquiries and options')}
                    />
                    {open.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('Nothing open.')}
                        </p>
                    )}
                    <ul className="divide-y rounded-xl border text-sm">
                        {open.map((e) => (
                            <li key={e.id}>
                                <Link
                                    href={EventController.show(e.id)}
                                    className="flex flex-wrap items-center gap-3 px-3 py-2 hover:bg-muted/50"
                                >
                                    <span className="w-24">{e.date}</span>
                                    <span className="min-w-48 flex-1 font-medium">
                                        {e.company ?? e.client_name}
                                    </span>
                                    <span>{e.type_label}</span>
                                    <span>
                                        {t(':people people', {
                                            people: e.people,
                                        })}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {e.space ?? '—'} · {e.block ?? '—'}
                                    </span>
                                    <Badge
                                        variant={
                                            e.waitlisted
                                                ? 'outline'
                                                : e.status === 'option'
                                                  ? 'secondary'
                                                  : 'outline'
                                        }
                                    >
                                        {e.waitlisted
                                            ? t('Waiting list')
                                            : e.status_label}
                                        {e.option_until &&
                                            !e.waitlisted &&
                                            ` → ${e.option_until}`}
                                    </Badge>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

Events.layout = {
    breadcrumbs: [{ title: 'Events', href: EventController.index() }],
};
