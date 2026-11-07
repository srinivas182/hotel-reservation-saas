import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import EventQuoteController from '@/actions/App/Domains/Events/Http/Controllers/EventQuoteController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

export type Quote = {
    id: number;
    version: number;
    status: string;
    status_label: string;
    valid_until: string;
    subtotal: string;
    discount: string | null;
    discount_reason: string | null;
    tax: string;
    total: string;
    deposit: string;
    accepted: {
        name: string;
        title: string | null;
        via: string;
        at: string;
    } | null;
    lines: {
        description: string;
        quantity: number;
        unit: string;
        total: string;
    }[];
};
type CatalogItem = { id: number; kind: string; name: string; unit: string };
type Line = {
    kind: string;
    ref: number | null;
    description: string;
    quantity: number;
    unit_price: string;
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function Builder({
    eventId,
    people,
    catalog,
    canDiscount,
    onDone,
}: {
    eventId: string;
    people: number;
    catalog: CatalogItem[];
    canDiscount: boolean;
    onDone: () => void;
}) {
    const form = useForm({
        lines: [
            {
                kind: 'space',
                ref: null,
                description: '',
                quantity: 1,
                unit_price: '',
            },
        ] as Line[],
        discount: '',
        discount_reason: '',
        valid_until: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const set = (i: number, patch: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    lines: d.lines.map((l) => ({
                        ...l,
                        unit_price: l.kind === 'free' ? l.unit_price : null,
                        ref:
                            l.kind === 'package' || l.kind === 'extra'
                                ? l.ref
                                : null,
                    })),
                    discount: d.discount === '' ? null : d.discount,
                    valid_until: d.valid_until || null,
                }));
                form.post(EventQuoteController.store.url(eventId), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="flex flex-col gap-2 rounded-xl border p-3 text-sm"
        >
            {form.data.lines.map((line, i) => (
                <div key={i} className="flex flex-wrap items-center gap-2">
                    <select
                        aria-label={t('Line')}
                        className={selectClass}
                        value={line.kind}
                        onChange={(e) =>
                            set(i, { kind: e.target.value, ref: null })
                        }
                    >
                        <option value="space">
                            {t('Space (price of the time block)')}
                        </option>
                        <option value="package">{t('Package')}</option>
                        <option value="extra">{t('Extra')}</option>
                        <option value="free">{t('Free line')}</option>
                    </select>
                    {(line.kind === 'package' || line.kind === 'extra') && (
                        <select
                            aria-label={t('Item')}
                            className={selectClass}
                            value={line.ref ?? ''}
                            onChange={(e) => {
                                const item = catalog.find(
                                    (c) => c.id === Number(e.target.value),
                                );
                                set(i, {
                                    ref: Number(e.target.value),
                                    quantity:
                                        item?.unit === 'per_person'
                                            ? people
                                            : line.quantity,
                                });
                            }}
                        >
                            <option value="">{t('Choose')}</option>
                            {catalog
                                .filter((c) => c.kind === line.kind)
                                .map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                        </select>
                    )}
                    {line.kind === 'free' && (
                        <>
                            <Input
                                aria-label={t('Description')}
                                placeholder={t('Description')}
                                className="w-56"
                                value={line.description}
                                onChange={(e) =>
                                    set(i, { description: e.target.value })
                                }
                            />
                            <Input
                                aria-label={t('Unit price')}
                                placeholder={t('Unit price')}
                                type="number"
                                min={0}
                                className="w-32"
                                value={line.unit_price}
                                onChange={(e) =>
                                    set(i, { unit_price: e.target.value })
                                }
                            />
                        </>
                    )}
                    <Input
                        aria-label={t('Quantity')}
                        type="number"
                        min={1}
                        className="w-24"
                        value={line.quantity}
                        onChange={(e) =>
                            set(i, { quantity: Number(e.target.value) })
                        }
                    />
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            form.setData(
                                'lines',
                                form.data.lines.filter((_, j) => j !== i),
                            )
                        }
                    >
                        {t('Remove')}
                    </Button>
                </div>
            ))}
            <div>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        form.setData('lines', [
                            ...form.data.lines,
                            {
                                kind: 'package',
                                ref: null,
                                description: '',
                                quantity: people,
                                unit_price: '',
                            },
                        ])
                    }
                >
                    {t('Add a line')}
                </Button>
            </div>
            <div className="flex flex-wrap items-end gap-2">
                {canDiscount && (
                    <>
                        <Input
                            aria-label={t('Discount')}
                            placeholder={t('Discount')}
                            type="number"
                            min={0}
                            className="w-32"
                            value={form.data.discount}
                            onChange={(e) =>
                                form.setData('discount', e.target.value)
                            }
                        />
                        <Input
                            aria-label={t('Reason for the discount')}
                            placeholder={t('Reason for the discount')}
                            className="w-64"
                            value={form.data.discount_reason}
                            onChange={(e) =>
                                form.setData('discount_reason', e.target.value)
                            }
                        />
                    </>
                )}
                <label className="grid gap-0.5 text-xs">
                    {t('Valid until')}
                    <Input
                        type="date"
                        value={form.data.valid_until}
                        onChange={(e) =>
                            form.setData('valid_until', e.target.value)
                        }
                    />
                </label>
                <Button disabled={form.processing}>
                    {t('Create the quote')}
                </Button>
            </div>
            <InputError message={errors.lines ?? errors.discount_reason} />
        </form>
    );
}

export function EventQuotes({
    eventId,
    people,
    quotes,
    catalog,
    canManage,
    canDiscount,
}: {
    eventId: string;
    people: number;
    quotes: Quote[];
    catalog: CatalogItem[];
    canManage: boolean;
    canDiscount: boolean;
}) {
    const [building, setBuilding] = useState(false);
    const [link, setLink] = useState<string | null>(null);

    const send = (quote: Quote) =>
        router.post(
            EventQuoteController.send.url(quote.id),
            {},
            {
                preserveScroll: true,
                onFlash: (flash) =>
                    setLink(
                        (flash as { quote_link?: string }).quote_link ?? null,
                    ),
            },
        );
    const acceptByStaff = (quote: Quote) => {
        const name = window.prompt(t('Who accepted the quote (name)?'));
        if (name) {
            router.post(
                EventQuoteController.accept.url(quote.id),
                { name },
                { preserveScroll: true },
            );
        }
    };

    return (
        <section className="flex flex-col gap-2">
            <div className="flex items-center justify-between">
                <Heading variant="small" title={t('Quotes')} />
                {canManage && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setBuilding(!building)}
                    >
                        {building ? t('Close') : t('New quote version')}
                    </Button>
                )}
            </div>
            {building && (
                <Builder
                    eventId={eventId}
                    people={people}
                    catalog={catalog}
                    canDiscount={canDiscount}
                    onDone={() => setBuilding(false)}
                />
            )}
            {link && (
                <div className="rounded-lg border border-primary p-3 text-sm">
                    {t('Send this link to the client (shown once):')}{' '}
                    <span className="font-mono break-all">{link}</span>{' '}
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => navigator.clipboard?.writeText(link)}
                    >
                        {t('Copy')}
                    </Button>
                </div>
            )}
            {quotes.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    {t('No quote yet.')}
                </p>
            )}
            {quotes.map((q) => (
                <details
                    key={q.id}
                    open={q === quotes[0]}
                    className="rounded-xl border p-3 text-sm"
                >
                    <summary className="flex cursor-pointer flex-wrap items-center gap-2">
                        <b>V{q.version}</b>{' '}
                        <Badge
                            variant={
                                q.status === 'accepted' ? 'default' : 'outline'
                            }
                        >
                            {q.status_label}
                        </Badge>{' '}
                        {q.total} ·{' '}
                        {t('valid until :date', { date: q.valid_until })}
                    </summary>
                    <table className="mt-2 w-full">
                        <tbody>
                            {q.lines.map((l, i) => (
                                <tr key={i} className="border-t">
                                    <td className="py-1">{l.description}</td>
                                    <td className="text-right">
                                        {l.quantity} × {l.unit}
                                    </td>
                                    <td className="text-right">{l.total}</td>
                                </tr>
                            ))}
                            {q.discount && (
                                <tr className="border-t">
                                    <td>
                                        {t('Discount')}{' '}
                                        <span className="text-muted-foreground">
                                            ({q.discount_reason})
                                        </span>
                                    </td>
                                    <td />
                                    <td className="text-right">
                                        −{q.discount}
                                    </td>
                                </tr>
                            )}
                            <tr className="border-t font-medium">
                                <td>
                                    {t('Total (taxes :tax)', { tax: q.tax })}
                                </td>
                                <td />
                                <td className="text-right">{q.total}</td>
                            </tr>
                            <tr>
                                <td className="text-muted-foreground">
                                    {t('Deposit to confirm')}
                                </td>
                                <td />
                                <td className="text-right">{q.deposit}</td>
                            </tr>
                        </tbody>
                    </table>
                    {q.accepted && (
                        <p className="mt-2 text-emerald-700 dark:text-emerald-400">
                            {t('Accepted by :name on :at (:via)', {
                                name: `${q.accepted.name}${q.accepted.title ? `, ${q.accepted.title}` : ''}`,
                                at: q.accepted.at,
                                via:
                                    q.accepted.via === 'online'
                                        ? t('online')
                                        : t('recorded by staff'),
                            })}
                        </p>
                    )}
                    {canManage && ['draft', 'sent'].includes(q.status) && (
                        <div className="mt-2 flex gap-2">
                            <Button size="sm" onClick={() => send(q)}>
                                {q.status === 'sent'
                                    ? t('New link')
                                    : t('Send')}
                            </Button>
                            {q.status === 'sent' && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => acceptByStaff(q)}
                                >
                                    {t('Accepted on paper')}
                                </Button>
                            )}
                        </div>
                    )}
                </details>
            ))}
        </section>
    );
}
