import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import BookingActionController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingActionController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { QuoteResult } from '@/components/rates/try-a-stay';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

export type Actions = {
    confirm: boolean;
    modify: boolean;
    cancel: boolean;
    no_show: boolean;
    assign_rooms: boolean;
    check_in: boolean;
    check_out: boolean;
    early_departure: boolean;
    override_price: boolean;
};

export type EditStay = {
    room_category_id: string;
    check_in: string;
    check_out: string;
    adults: number;
    children: string;
    rooms: number;
    breakfast: boolean;
    extra_bed: boolean;
};

type RoomLine = {
    position: number;
    room_id: string | null;
    number: string | null;
};
type EligibleRoom = { id: string; number: string; floor: string | null };

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export function RoomNumbers({
    bookingId,
    lines,
    eligible,
    suggested,
    canAssign,
    canCheckIn,
}: {
    bookingId: string;
    lines: RoomLine[];
    eligible: EligibleRoom[];
    suggested: string | null;
    canAssign: boolean;
    canCheckIn: boolean;
}) {
    const initial: Record<number, string> = {};
    const used = new Set(lines.map((line) => line.room_id).filter(Boolean));
    let suggestion = suggested;

    for (const line of lines) {
        if (line.room_id) {
            initial[line.position] = line.room_id;
        } else if (suggestion && !used.has(suggestion)) {
            initial[line.position] = suggestion;
            used.add(suggestion);
            suggestion =
                eligible.find((room) => !used.has(room.id))?.id ?? null;
        } else {
            initial[line.position] = '';
        }
    }

    const [choice, setChoice] = useState<Record<number, string>>(initial);

    const save = (position: number) =>
        router.put(
            BookingActionController.assignRoom.url({
                booking: bookingId,
                position,
            }),
            { room_id: choice[position] },
            { preserveScroll: true },
        );

    return (
        <div className="flex flex-col gap-3">
            <ul className="flex flex-col gap-2">
                {lines.map((line) => (
                    <li
                        key={line.position}
                        className="flex flex-wrap items-center gap-2 text-sm"
                    >
                        <span className="w-16 text-muted-foreground">
                            {t('Room :n', { n: line.position })}
                        </span>
                        {canAssign ? (
                            <>
                                <select
                                    aria-label={t('Room number for room :n', {
                                        n: line.position,
                                    })}
                                    className={`${selectClass} w-40`}
                                    value={choice[line.position] ?? ''}
                                    onChange={(e) =>
                                        setChoice({
                                            ...choice,
                                            [line.position]: e.target.value,
                                        })
                                    }
                                >
                                    <option value="">{t('Choose')}</option>
                                    {eligible.map((room) => (
                                        <option key={room.id} value={room.id}>
                                            {room.number}
                                            {room.floor
                                                ? ` · ${t('floor :floor', { floor: room.floor })}`
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                                {choice[line.position] &&
                                    choice[line.position] !== line.room_id && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => save(line.position)}
                                        >
                                            {t('Save')}
                                        </Button>
                                    )}
                            </>
                        ) : (
                            <span>{line.number ?? t('not given yet')}</span>
                        )}
                    </li>
                ))}
            </ul>
            {canCheckIn && (
                <Button
                    className="w-fit"
                    onClick={() =>
                        router.post(
                            BookingActionController.checkIn.url(bookingId),
                            { rooms: choice },
                            { preserveScroll: true },
                        )
                    }
                >
                    {t('Check in')}
                </Button>
            )}
        </div>
    );
}

export function ModifyStay({
    bookingId,
    stay,
    categories,
    quote,
    canOverride,
    onDone,
}: {
    bookingId: string;
    stay: EditStay;
    categories: { id: string; name: string; extra_bed_allowed: boolean }[];
    quote?: QuoteResult;
    canOverride: boolean;
    onDone: () => void;
}) {
    const form = useForm({ ...stay, override_total: '', override_reason: '' });
    const { data, setData } = form;
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (data.check_out <= data.check_in) {
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

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            override_total:
                values.override_total === '' ? null : values.override_total,
            override_reason:
                values.override_reason === '' ? null : values.override_reason,
        }));
        form.put(BookingActionController.update.url(bookingId), {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-3 rounded-xl border p-4"
        >
            <Heading
                variant="small"
                title={t('Change the stay')}
                description={t(
                    'The stay is priced again. The rooms are checked without this booking’s own nights.',
                )}
            />
            <div className="grid gap-3 sm:grid-cols-4">
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor="edit-category">{t('Room category')}</Label>
                    <select
                        id="edit-category"
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
                    <Label htmlFor="edit-in">{t('Check-in')}</Label>
                    <Input
                        id="edit-in"
                        type="date"
                        value={data.check_in}
                        onChange={(e) => setData('check_in', e.target.value)}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="edit-out">{t('Check-out')}</Label>
                    <Input
                        id="edit-out"
                        type="date"
                        value={data.check_out}
                        onChange={(e) => setData('check_out', e.target.value)}
                    />
                    <InputError message={errors.check_out} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="edit-adults">{t('Adults')}</Label>
                    <Input
                        id="edit-adults"
                        type="number"
                        min={1}
                        value={data.adults}
                        onChange={(e) =>
                            setData('adults', Number(e.target.value))
                        }
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="edit-children">
                        {t('Children ages (e.g. 4, 9)')}
                    </Label>
                    <Input
                        id="edit-children"
                        value={data.children}
                        onChange={(e) => setData('children', e.target.value)}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="edit-rooms">{t('Rooms')}</Label>
                    <Input
                        id="edit-rooms"
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
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={data.extra_bed}
                            onChange={(e) =>
                                setData('extra_bed', e.target.checked)
                            }
                        />
                        {t('Extra bed')}
                    </label>
                </div>
                {canOverride && (
                    <>
                        <div className="grid gap-1 sm:col-span-2">
                            <Label htmlFor="edit-override">
                                {t(
                                    'Agreed total (optional, must be given again)',
                                )}
                            </Label>
                            <Input
                                id="edit-override"
                                type="number"
                                min={0}
                                step="any"
                                value={data.override_total}
                                onChange={(e) =>
                                    setData('override_total', e.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1 sm:col-span-2">
                            <Label htmlFor="edit-reason">
                                {t('Reason for the different price')}
                            </Label>
                            <Input
                                id="edit-reason"
                                value={data.override_reason}
                                onChange={(e) =>
                                    setData('override_reason', e.target.value)
                                }
                            />
                            <InputError message={errors.override_reason} />
                        </div>
                    </>
                )}
            </div>
            {quote && (
                <div className="text-sm">
                    {quote.issues.length > 0 && (
                        <p className="text-destructive">
                            {quote.issues.join(' ')}
                        </p>
                    )}
                    <p className="font-medium">
                        {t('New price:')} {quote.total}
                    </p>
                </div>
            )}
            <div className="flex gap-2">
                <Button disabled={form.processing}>{t('Save changes')}</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}

export function CancelBooking({
    bookingId,
    onDone,
}: {
    bookingId: string;
    onDone: () => void;
}) {
    const form = useForm({ reason: '' });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(BookingActionController.cancel.url(bookingId), {
                    preserveScroll: true,
                    onSuccess: () => onDone(),
                });
            }}
            className="flex flex-col gap-2 rounded-xl border border-destructive/40 p-4"
        >
            <Label htmlFor="cancel-reason">{t('Reason for cancelling')}</Label>
            <Input
                id="cancel-reason"
                value={form.data.reason}
                onChange={(e) => form.setData('reason', e.target.value)}
                required
                maxLength={300}
            />
            <InputError message={form.errors.reason} />
            <div className="flex gap-2">
                <Button variant="destructive" disabled={form.processing}>
                    {t('Cancel booking')}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Keep booking')}
                </Button>
            </div>
        </form>
    );
}
