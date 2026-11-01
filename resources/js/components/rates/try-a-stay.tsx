import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

export type QuoteResult = {
    bookable: boolean;
    issues: string[];
    nights: {
        date: string;
        rate: string;
        label: string | null;
        available: number;
    }[];
    lines: { label: string; quantity: number; amount: string }[];
    subtotal: string;
    taxes: { name: string; amount: string; inclusive: boolean }[];
    total: string;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50';

function today(offset = 0): string {
    const date = new Date();
    date.setDate(date.getDate() + offset);

    return date.toISOString().slice(0, 10);
}

/**
 * "Try a stay": runs the quote engine through a partial reload of the
 * current page (the page must offer an optional "quote" prop).
 */
export function TryAStay({
    categories,
    quote,
}: {
    categories: { id: string; name: string }[];
    quote?: QuoteResult;
}) {
    const [stay, setStay] = useState({
        category: categories[0]?.id ?? '',
        check_in: today(7),
        check_out: today(9),
        adults: '2',
        children: '',
        rooms: '1',
        breakfast: false,
        extra_bed: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.reload({
            only: ['quote'],
            data: {
                ...stay,
                breakfast: stay.breakfast ? 1 : 0,
                extra_bed: stay.extra_bed ? 1 : 0,
            },
        });
    };

    return (
        <section className="flex flex-col gap-4 rounded-xl border p-4">
            <Heading
                variant="small"
                title={t('Try a stay')}
                description={t('See exactly what a guest would be quoted.')}
            />
            <form
                onSubmit={submit}
                className="grid gap-3 sm:grid-cols-4 sm:items-end"
            >
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor="quote-category">{t('Room category')}</Label>
                    <select
                        id="quote-category"
                        className={selectClass}
                        value={stay.category}
                        onChange={(e) =>
                            setStay({ ...stay, category: e.target.value })
                        }
                    >
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="quote-in">{t('Check-in')}</Label>
                    <Input
                        id="quote-in"
                        type="date"
                        value={stay.check_in}
                        onChange={(e) =>
                            setStay({ ...stay, check_in: e.target.value })
                        }
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="quote-out">{t('Check-out')}</Label>
                    <Input
                        id="quote-out"
                        type="date"
                        value={stay.check_out}
                        onChange={(e) =>
                            setStay({ ...stay, check_out: e.target.value })
                        }
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="quote-adults">{t('Adults')}</Label>
                    <Input
                        id="quote-adults"
                        type="number"
                        min="1"
                        value={stay.adults}
                        onChange={(e) =>
                            setStay({ ...stay, adults: e.target.value })
                        }
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="quote-children">
                        {t('Children ages (e.g. 4, 9)')}
                    </Label>
                    <Input
                        id="quote-children"
                        value={stay.children}
                        onChange={(e) =>
                            setStay({ ...stay, children: e.target.value })
                        }
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="quote-rooms">{t('Rooms')}</Label>
                    <Input
                        id="quote-rooms"
                        type="number"
                        min="1"
                        value={stay.rooms}
                        onChange={(e) =>
                            setStay({ ...stay, rooms: e.target.value })
                        }
                    />
                </div>
                <div className="flex flex-col gap-1 text-sm">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={stay.breakfast}
                            onChange={(e) =>
                                setStay({
                                    ...stay,
                                    breakfast: e.target.checked,
                                })
                            }
                        />
                        {t('Breakfast')}
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={stay.extra_bed}
                            onChange={(e) =>
                                setStay({
                                    ...stay,
                                    extra_bed: e.target.checked,
                                })
                            }
                        />
                        {t('Extra bed')}
                    </label>
                </div>
                <Button className="sm:col-span-4 sm:w-fit">
                    {t('Calculate')}
                </Button>
            </form>

            {quote && (
                <div className="flex flex-col gap-3 text-sm">
                    {quote.issues.length > 0 && (
                        <ul className="rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-destructive">
                            {quote.issues.map((issue) => (
                                <li key={issue}>{issue}</li>
                            ))}
                        </ul>
                    )}
                    {quote.nights.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {quote.nights.map((night) => (
                                <div
                                    key={night.date}
                                    className="rounded-md border px-2 py-1"
                                >
                                    <div className="text-xs text-muted-foreground">
                                        {night.date}
                                    </div>
                                    <div>{night.rate}</div>
                                    {night.label && (
                                        <div className="text-xs">
                                            {night.label}
                                        </div>
                                    )}
                                    <div className="text-xs text-muted-foreground">
                                        {t(':count free', {
                                            count: night.available,
                                        })}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    <dl className="grid gap-1">
                        {quote.lines.map((line) => (
                            <div
                                key={line.label}
                                className="flex justify-between"
                            >
                                <dt className="text-muted-foreground">
                                    {line.label} × {line.quantity}
                                </dt>
                                <dd>{line.amount}</dd>
                            </div>
                        ))}
                        {quote.taxes.map((tax) => (
                            <div
                                key={tax.name}
                                className="flex justify-between"
                            >
                                <dt className="text-muted-foreground">
                                    {tax.name}{' '}
                                    {tax.inclusive
                                        ? t('(included)')
                                        : t('(added)')}
                                </dt>
                                <dd>{tax.amount}</dd>
                            </div>
                        ))}
                        <div className="mt-1 flex justify-between border-t pt-2 font-medium">
                            <dt>
                                {quote.bookable
                                    ? t('Guest pays')
                                    : t('Guest would pay')}
                            </dt>
                            <dd>{quote.total}</dd>
                        </div>
                    </dl>
                </div>
            )}
        </section>
    );
}
