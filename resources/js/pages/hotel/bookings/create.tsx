import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { FormEvent } from 'react';
import BookingController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { QuoteResult } from '@/components/rates/try-a-stay';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type Props = {
    currency: string;
    today: string;
    categories: { id: string; name: string; extra_bed_allowed: boolean }[];
    channels: { value: string; label: string }[];
    locales: Record<string, string>;
    canOverridePrice: boolean;
    quote?: QuoteResult;
    guest?: {
        first_name: string;
        last_name: string | null;
        email: string | null;
        preferred_locale: string | null;
        bookings: number;
    } | null;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

function addDays(date: string, days: number): string {
    const value = new Date(`${date}T00:00:00`);
    value.setDate(value.getDate() + days);

    return value.toISOString().slice(0, 10);
}

export default function CreateBooking({
    currency,
    today,
    categories,
    channels,
    locales,
    canOverridePrice,
    quote,
    guest,
}: Props) {
    const form = useForm({
        room_category_id: categories[0]?.id ?? '',
        check_in: today,
        check_out: addDays(today, 1),
        adults: 2,
        children: '',
        rooms: 1,
        breakfast: false,
        extra_bed: false,
        status: 'confirmed',
        channel: 'walk_in',
        external_reference: '',
        group_label: '',
        first_name: '',
        last_name: '',
        phone: '',
        email: '',
        preferred_locale: '',
        notes: '',
        payment_note: '',
        override_total: '',
        override_reason: '',
    });
    const { data, setData } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const category = categories.find((c) => c.id === data.room_category_id);
    const lastGuestLookup = useRef('');

    // Live price whenever the stay changes.
    useEffect(() => {
        if (!data.room_category_id || data.check_out <= data.check_in) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.reload({
                only: ['quote'],
                data: {
                    category: data.room_category_id,
                    check_in: data.check_in,
                    check_out: data.check_out,
                    adults: data.adults,
                    children: data.children,
                    rooms: data.rooms,
                    breakfast: data.breakfast ? 1 : 0,
                    extra_bed: data.extra_bed ? 1 : 0,
                },
            });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [
        data.room_category_id,
        data.check_in,
        data.check_out,
        data.adults,
        data.children,
        data.rooms,
        data.breakfast,
        data.extra_bed,
    ]);

    // Known guests are recognised by phone.
    useEffect(() => {
        if (guest && lastGuestLookup.current !== '' && data.first_name === '') {
            setData((values) => ({
                ...values,
                first_name: guest.first_name,
                last_name: guest.last_name ?? '',
                email: guest.email ?? '',
                preferred_locale: guest.preferred_locale ?? '',
            }));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [guest]);

    const lookUpGuest = () => {
        const phone = data.phone.trim();

        if (phone.length >= 7 && phone !== lastGuestLookup.current) {
            lastGuestLookup.current = phone;
            router.reload({ only: ['guest'], data: { phone } });
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            override_total:
                values.override_total === '' ? null : values.override_total,
            override_reason:
                values.override_reason === '' ? null : values.override_reason,
            preferred_locale:
                values.preferred_locale === '' ? null : values.preferred_locale,
        }));
        form.post(BookingController.store.url());
    };

    return (
        <>
            <Head title={t('New booking')} />
            <form
                onSubmit={submit}
                className="grid max-w-5xl gap-6 p-4 lg:grid-cols-[1fr_20rem]"
            >
                <div className="flex flex-col gap-6">
                    <Heading
                        title={t('New booking')}
                        description={t(
                            'Walk-in, phone, email or another booking site.',
                        )}
                    />

                    <section className="grid gap-3 rounded-xl border p-4 sm:grid-cols-4">
                        <div className="grid gap-1 sm:col-span-2">
                            <Label htmlFor="room_category_id">
                                {t('Room category')}
                            </Label>
                            <select
                                id="room_category_id"
                                className={selectClass}
                                value={data.room_category_id}
                                onChange={(e) =>
                                    setData('room_category_id', e.target.value)
                                }
                            >
                                {categories.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.room_category_id} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="check_in">{t('Check-in')}</Label>
                            <Input
                                id="check_in"
                                type="date"
                                value={data.check_in}
                                onChange={(e) => {
                                    const value = e.target.value;
                                    setData((values) => ({
                                        ...values,
                                        check_in: value,
                                        check_out:
                                            values.check_out <= value
                                                ? addDays(value, 1)
                                                : values.check_out,
                                    }));
                                }}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="check_out">{t('Check-out')}</Label>
                            <Input
                                id="check_out"
                                type="date"
                                min={addDays(data.check_in, 1)}
                                value={data.check_out}
                                onChange={(e) =>
                                    setData('check_out', e.target.value)
                                }
                            />
                            <InputError message={errors.check_out} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="adults">{t('Adults')}</Label>
                            <Input
                                id="adults"
                                type="number"
                                min={1}
                                value={data.adults}
                                onChange={(e) =>
                                    setData('adults', Number(e.target.value))
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="children">
                                {t('Children ages (e.g. 4, 9)')}
                            </Label>
                            <Input
                                id="children"
                                value={data.children}
                                onChange={(e) =>
                                    setData('children', e.target.value)
                                }
                            />
                            <InputError message={errors['children_ages.0']} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="rooms">{t('Rooms')}</Label>
                            <Input
                                id="rooms"
                                type="number"
                                min={1}
                                value={data.rooms}
                                onChange={(e) =>
                                    setData('rooms', Number(e.target.value))
                                }
                            />
                        </div>
                        <div className="flex flex-col justify-end gap-1 text-sm">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={data.breakfast}
                                    onChange={(e) =>
                                        setData('breakfast', e.target.checked)
                                    }
                                />
                                {t('Breakfast')}
                            </label>
                            {category?.extra_bed_allowed && (
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={data.extra_bed}
                                        onChange={(e) =>
                                            setData(
                                                'extra_bed',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    {t('Extra bed')}
                                </label>
                            )}
                        </div>
                    </section>

                    <section className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="phone">{t('Phone')}</Label>
                            <Input
                                id="phone"
                                type="tel"
                                placeholder="+2250700000000"
                                value={data.phone}
                                onChange={(e) =>
                                    setData('phone', e.target.value)
                                }
                                onBlur={lookUpGuest}
                            />
                            <InputError message={errors.phone} />
                            {guest && (
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'Known guest: :count earlier booking(s).',
                                        { count: guest.bookings },
                                    )}
                                </p>
                            )}
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="email">{t('Email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                            />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="first_name">
                                {t('First name')}
                            </Label>
                            <Input
                                id="first_name"
                                value={data.first_name}
                                onChange={(e) =>
                                    setData('first_name', e.target.value)
                                }
                                required
                            />
                            <InputError message={errors.first_name} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="last_name">{t('Last name')}</Label>
                            <Input
                                id="last_name"
                                value={data.last_name}
                                onChange={(e) =>
                                    setData('last_name', e.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="preferred_locale">
                                {t('Language')}
                            </Label>
                            <select
                                id="preferred_locale"
                                className={selectClass}
                                value={data.preferred_locale}
                                onChange={(e) =>
                                    setData('preferred_locale', e.target.value)
                                }
                            >
                                <option value="">—</option>
                                {Object.entries(locales).map(
                                    ([code, label]) => (
                                        <option key={code} value={code}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                    </section>

                    <section className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="channel">{t('Channel')}</Label>
                            <select
                                id="channel"
                                className={selectClass}
                                value={data.channel}
                                onChange={(e) =>
                                    setData('channel', e.target.value)
                                }
                            >
                                {channels.map((c) => (
                                    <option key={c.value} value={c.value}>
                                        {c.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="external_reference">
                                {t('Reference on the other site')}
                            </Label>
                            <Input
                                id="external_reference"
                                value={data.external_reference}
                                onChange={(e) =>
                                    setData(
                                        'external_reference',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="status">{t('Status')}</Label>
                            <select
                                id="status"
                                className={selectClass}
                                value={data.status}
                                onChange={(e) =>
                                    setData('status', e.target.value)
                                }
                            >
                                <option value="confirmed">
                                    {t('Confirmed')}
                                </option>
                                <option value="tentative">
                                    {t(
                                        'Tentative (holds rooms until confirmed)',
                                    )}
                                </option>
                            </select>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="group_label">
                                {t('Group (optional)')}
                            </Label>
                            <Input
                                id="group_label"
                                value={data.group_label}
                                onChange={(e) =>
                                    setData('group_label', e.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1 sm:col-span-2">
                            <Label htmlFor="notes">{t('Notes')}</Label>
                            <Input
                                id="notes"
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1 sm:col-span-2">
                            <Label htmlFor="payment_note">
                                {t('Payment or deposit note')}
                            </Label>
                            <Input
                                id="payment_note"
                                value={data.payment_note}
                                onChange={(e) =>
                                    setData('payment_note', e.target.value)
                                }
                            />
                        </div>
                        {canOverridePrice && (
                            <>
                                <div className="grid gap-1">
                                    <Label htmlFor="override_total">
                                        {t(
                                            'Agreed total (:currency, optional)',
                                            { currency },
                                        )}
                                    </Label>
                                    <Input
                                        id="override_total"
                                        type="number"
                                        min={0}
                                        step="any"
                                        value={data.override_total}
                                        onChange={(e) =>
                                            setData(
                                                'override_total',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.override_total}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="override_reason">
                                        {t('Reason for the different price')}
                                    </Label>
                                    <Input
                                        id="override_reason"
                                        value={data.override_reason}
                                        onChange={(e) =>
                                            setData(
                                                'override_reason',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.override_reason}
                                    />
                                </div>
                            </>
                        )}
                    </section>
                </div>

                <aside className="flex h-fit flex-col gap-3 rounded-xl border p-4 lg:sticky lg:top-4">
                    <Heading variant="small" title={t('Price')} />
                    {!quote && (
                        <p className="text-sm text-muted-foreground">
                            {t('Choose the stay to see the price.')}
                        </p>
                    )}
                    {quote && (
                        <div className="flex flex-col gap-2 text-sm">
                            {quote.issues.length > 0 && (
                                <ul className="rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-destructive">
                                    {quote.issues.map((issue) => (
                                        <li key={issue}>{issue}</li>
                                    ))}
                                </ul>
                            )}
                            <dl className="grid gap-1">
                                {quote.lines.map((line) => (
                                    <div
                                        key={line.label}
                                        className="flex justify-between gap-2"
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
                                        className="flex justify-between gap-2"
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
                                    <dt>{t('Total')}</dt>
                                    <dd>{quote.total}</dd>
                                </div>
                            </dl>
                        </div>
                    )}
                    <Button
                        disabled={form.processing || quote?.bookable === false}
                    >
                        {t('Save booking')}
                    </Button>
                </aside>
            </form>
        </>
    );
}

CreateBooking.layout = {
    breadcrumbs: [
        { title: 'Bookings', href: BookingController.index() },
        { title: 'New booking', href: BookingController.create() },
    ],
};
