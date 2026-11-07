import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import RoomBlockController from '@/actions/App/Domains/Events/Http/Controllers/RoomBlockController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

export type Block = {
    id: number;
    category: string | null;
    check_in: string;
    check_out: string;
    rooms: number;
    booked: number;
    remaining: number;
    cutoff: string;
    released: boolean;
    rate: string;
    bookings: { id: string; reference: string; guest: string | null }[];
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function BookFromBlock({ block }: { block: Block }) {
    const form = useForm({
        first_name: '',
        last_name: '',
        phone: '',
        adults: 1,
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(RoomBlockController.book.url(block.id), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
            className="flex flex-wrap items-end gap-2"
        >
            <Input
                aria-label={t('First name')}
                placeholder={t('First name')}
                className="w-36"
                value={form.data.first_name}
                onChange={(e) => form.setData('first_name', e.target.value)}
            />
            <Input
                aria-label={t('Last name')}
                placeholder={t('Last name')}
                className="w-36"
                value={form.data.last_name}
                onChange={(e) => form.setData('last_name', e.target.value)}
            />
            <Input
                aria-label={t('Phone')}
                placeholder={t('Phone')}
                type="tel"
                className="w-40"
                value={form.data.phone}
                onChange={(e) => form.setData('phone', e.target.value)}
            />
            <Input
                aria-label={t('Adults')}
                type="number"
                min={1}
                className="w-20"
                value={form.data.adults}
                onChange={(e) => form.setData('adults', Number(e.target.value))}
            />
            <Button size="sm" disabled={form.processing}>
                {t('Book a room from the block')}
            </Button>
            <InputError message={errors.block ?? errors.first_name} />
        </form>
    );
}

export function RoomBlocks({
    eventId,
    eventDate,
    blocks,
    categories,
    canManage,
    canBook,
}: {
    eventId: string;
    eventDate: string;
    blocks: Block[];
    categories: { id: string; name: string }[];
    canManage: boolean;
    canBook: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const form = useForm({
        room_category_id: categories[0]?.id ?? '',
        check_in: eventDate,
        check_out: '',
        rooms: 10,
        rate: '',
        cutoff_date: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <section className="flex flex-col gap-2 text-sm">
            <div className="flex items-center justify-between">
                <Heading variant="small" title={t('Rooms for attendees')} />
                {canManage && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setAdding(!adding)}
                    >
                        {adding ? t('Close') : t('Block rooms')}
                    </Button>
                )}
            </div>
            {adding && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(RoomBlockController.store.url(eventId), {
                            preserveScroll: true,
                            onSuccess: () => setAdding(false),
                        });
                    }}
                    className="flex flex-wrap items-end gap-2 rounded-xl border p-3"
                >
                    <select
                        aria-label={t('Room category')}
                        className={selectClass}
                        value={form.data.room_category_id}
                        onChange={(e) =>
                            form.setData('room_category_id', e.target.value)
                        }
                    >
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                    <label className="grid gap-0.5 text-xs">
                        {t('Arrival')}
                        <Input
                            type="date"
                            value={form.data.check_in}
                            onChange={(e) =>
                                form.setData('check_in', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-0.5 text-xs">
                        {t('Departure')}
                        <Input
                            type="date"
                            value={form.data.check_out}
                            onChange={(e) =>
                                form.setData('check_out', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-0.5 text-xs">
                        {t('Rooms per night')}
                        <Input
                            type="number"
                            min={1}
                            className="w-24"
                            value={form.data.rooms}
                            onChange={(e) =>
                                form.setData('rooms', Number(e.target.value))
                            }
                        />
                    </label>
                    <label className="grid gap-0.5 text-xs">
                        {t('Group rate per night')}
                        <Input
                            type="number"
                            min={0}
                            className="w-32"
                            value={form.data.rate}
                            onChange={(e) =>
                                form.setData('rate', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-0.5 text-xs">
                        {t('Cut-off date')}
                        <Input
                            type="date"
                            value={form.data.cutoff_date}
                            onChange={(e) =>
                                form.setData('cutoff_date', e.target.value)
                            }
                        />
                    </label>
                    <Button size="sm" disabled={form.processing}>
                        {t('Block')}
                    </Button>
                    <InputError
                        message={
                            errors.rooms ??
                            errors.check_out ??
                            errors.cutoff_date
                        }
                    />
                </form>
            )}
            {blocks.length === 0 && (
                <p className="text-muted-foreground">
                    {t('No rooms blocked.')}
                </p>
            )}
            {blocks.map((b) => (
                <div
                    key={b.id}
                    className="flex flex-col gap-2 rounded-xl border p-3"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{b.category}</span>
                        <span>
                            {b.check_in} → {b.check_out}
                        </span>
                        <span>
                            {t(':booked of :rooms booked', {
                                booked: b.booked,
                                rooms: b.rooms,
                            })}
                        </span>
                        <span className="text-muted-foreground">
                            {t(':rate per night', { rate: b.rate })}
                        </span>
                        {b.released ? (
                            <Badge variant="outline">{t('Released')}</Badge>
                        ) : (
                            <Badge variant="secondary">
                                {t('Cut-off :date', { date: b.cutoff })}
                            </Badge>
                        )}
                        {canManage && !b.released && (
                            <Button
                                size="sm"
                                variant="ghost"
                                className="ml-auto"
                                onClick={() =>
                                    window.confirm(
                                        t(
                                            'Put the unused rooms back on sale now?',
                                        ),
                                    ) &&
                                    router.post(
                                        RoomBlockController.release.url(b.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('Release now')}
                            </Button>
                        )}
                    </div>
                    {b.bookings.length > 0 && (
                        <p className="text-xs text-muted-foreground">
                            {b.bookings
                                .map(
                                    (bk) => `${bk.reference} ${bk.guest ?? ''}`,
                                )
                                .join(' · ')}
                        </p>
                    )}
                    {canBook && b.remaining > 0 && <BookFromBlock block={b} />}
                </div>
            ))}
        </section>
    );
}
