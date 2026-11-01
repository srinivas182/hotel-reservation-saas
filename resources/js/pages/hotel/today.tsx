import type { RequestPayload } from '@inertiajs/core';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import BookingActionController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingActionController';
import BookingController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingController';
import TodayController from '@/actions/App/Domains/Bookings/Http/Controllers/TodayController';
import { BookingStatusBadge } from '@/components/bookings/status-badge';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Item = {
    id: string;
    reference: string;
    status: string;
    status_label: string;
    guest: string | null;
    phone: string | null;
    category: string | null;
    check_in: string;
    check_out: string;
    rooms: number;
    room_numbers: string[];
    tentative_until: string | null;
    overdue: boolean;
    suggested_rooms: Record<string, string> | null;
};

type BoardRoom = {
    id: string;
    number: string;
    floor: string | null;
    state: 'free' | 'occupied' | 'arriving' | 'out_of_service';
    departing: boolean;
    arriving: boolean;
    booking_id: string | null;
    reference: string | null;
    guest: string | null;
};

type Props = {
    date: string;
    dateLabel: string;
    arrivals: Item[];
    inHouse: Item[];
    departures: Item[];
    noShows: Item[];
    tentative: Item[];
    freeTonight: { name: string; available: number; sellable: number }[];
    board: BoardRoom[];
    registerCheck: {
        matches: boolean;
        notes: string | null;
        by: string | null;
        at: string | null;
    } | null;
    can: { frontdesk: boolean; manage: boolean; create: boolean };
};

const tones: Record<BoardRoom['state'], string> = {
    free: 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950',
    occupied: 'border-sky-300 bg-sky-50 dark:border-sky-800 dark:bg-sky-950',
    arriving:
        'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950',
    out_of_service: 'border-muted bg-muted text-muted-foreground',
};

function Section({
    title,
    count,
    children,
}: {
    title: string;
    count: number;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-2 rounded-xl border p-4">
            <div className="flex items-center justify-between">
                <h2 className="font-medium">{title}</h2>
                <Badge variant="secondary">{count}</Badge>
            </div>
            {count === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('Nothing here.')}
                </p>
            ) : (
                <ul className="divide-y">{children}</ul>
            )}
        </section>
    );
}

function Line({ item, action }: { item: Item; action?: React.ReactNode }) {
    return (
        <li className="flex flex-wrap items-center gap-3 py-2 text-sm">
            <div className="min-w-0 flex-1">
                <Link
                    href={BookingController.show(item.id)}
                    className="font-medium hover:underline"
                >
                    {item.guest}
                </Link>{' '}
                <span className="text-muted-foreground">
                    · {item.reference}
                </span>
                <div className="text-muted-foreground">
                    {item.category} × {item.rooms}
                    {item.room_numbers.length > 0 &&
                        ` · ${t('room(s) :numbers', { numbers: item.room_numbers.join(', ') })}`}
                    {` · ${item.check_in} → ${item.check_out}`}
                </div>
            </div>
            {action}
        </li>
    );
}

function RegisterCheckForm({
    existing,
    canCheck,
}: {
    existing: Props['registerCheck'];
    canCheck: boolean;
}) {
    const form = useForm({ matches: true, notes: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(TodayController.registerCheck.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <section className="flex flex-col gap-3 rounded-xl border p-4">
            <Heading
                variant="small"
                title={t('Evening register check')}
                description={t(
                    'Compare tonight’s occupied rooms with the paper register.',
                )}
            />
            {existing && (
                <p
                    className={cn(
                        'text-sm',
                        existing.matches
                            ? 'text-emerald-700 dark:text-emerald-300'
                            : 'text-destructive',
                    )}
                >
                    {existing.matches
                        ? t('Checked at :time by :name: matches.', {
                              time: existing.at ?? '',
                              name: existing.by ?? '',
                          })
                        : t('Checked at :time by :name: differences found.', {
                              time: existing.at ?? '',
                              name: existing.by ?? '',
                          })}
                    {existing.notes && ` ${existing.notes}`}
                </p>
            )}
            {canCheck && (
                <form onSubmit={submit} className="flex flex-col gap-2">
                    <div className="flex flex-wrap gap-4 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="matches"
                                checked={form.data.matches}
                                onChange={() => form.setData('matches', true)}
                            />
                            {t('Matches the register')}
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="matches"
                                checked={!form.data.matches}
                                onChange={() => form.setData('matches', false)}
                            />
                            {t('Does not match')}
                        </label>
                    </div>
                    <Input
                        aria-label={t('Notes')}
                        placeholder={t('What is different?')}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                    <InputError message={form.errors.notes} />
                    <Button className="w-fit" disabled={form.processing}>
                        {t('Save check')}
                    </Button>
                </form>
            )}
        </section>
    );
}

export default function Today(props: Props) {
    const post = (url: string, data: RequestPayload = {}) =>
        router.post(url, data, { preserveScroll: true });

    return (
        <>
            <Head title={t('Today')} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={t('Today')} description={props.dateLabel} />
                    {props.can.create && (
                        <Button asChild>
                            <Link href={BookingController.create()}>
                                <Plus /> {t('New booking')}
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {props.freeTonight.map((category) => (
                        <div
                            key={category.name}
                            className="rounded-xl border p-3"
                        >
                            <div className="text-sm text-muted-foreground">
                                {category.name}
                            </div>
                            <div className="text-2xl font-semibold">
                                {category.available}
                                <span className="text-sm font-normal text-muted-foreground">
                                    {' '}
                                    / {category.sellable}
                                </span>
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {t('free tonight')}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Section
                        title={t('Arrivals')}
                        count={props.arrivals.length}
                    >
                        {props.arrivals.map((item) => (
                            <Line
                                key={item.id}
                                item={item}
                                action={
                                    item.status === 'tentative' ? (
                                        <BookingStatusBadge
                                            status={item.status}
                                            label={item.status_label}
                                        />
                                    ) : (
                                        props.can.frontdesk && (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    post(
                                                        BookingActionController.checkIn.url(
                                                            item.id,
                                                        ),
                                                        {
                                                            rooms:
                                                                item.suggested_rooms ??
                                                                {},
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Check in')}
                                            </Button>
                                        )
                                    )
                                }
                            />
                        ))}
                    </Section>

                    <Section
                        title={t('Departures')}
                        count={props.departures.length}
                    >
                        {props.departures.map((item) => (
                            <Line
                                key={item.id}
                                item={item}
                                action={
                                    <div className="flex items-center gap-2">
                                        {item.overdue && (
                                            <Badge variant="destructive">
                                                {t('Overdue')}
                                            </Badge>
                                        )}
                                        {props.can.frontdesk && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    post(
                                                        BookingActionController.checkOut.url(
                                                            item.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                {t('Check out')}
                                            </Button>
                                        )}
                                    </div>
                                }
                            />
                        ))}
                    </Section>

                    {props.noShows.length > 0 && (
                        <Section
                            title={t('Possible no-shows')}
                            count={props.noShows.length}
                        >
                            {props.noShows.map((item) => (
                                <Line
                                    key={item.id}
                                    item={item}
                                    action={
                                        props.can.manage && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    if (
                                                        window.confirm(
                                                            t(
                                                                'Mark this guest as a no-show? All nights go back on sale.',
                                                            ),
                                                        )
                                                    ) {
                                                        post(
                                                            BookingActionController.noShow.url(
                                                                item.id,
                                                            ),
                                                        );
                                                    }
                                                }}
                                            >
                                                {t('No-show')}
                                            </Button>
                                        )
                                    }
                                />
                            ))}
                        </Section>
                    )}

                    {props.tentative.length > 0 && (
                        <Section
                            title={t('Tentative bookings ending soon')}
                            count={props.tentative.length}
                        >
                            {props.tentative.map((item) => (
                                <Line
                                    key={item.id}
                                    item={item}
                                    action={
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs text-muted-foreground">
                                                {t('until :time', {
                                                    time:
                                                        item.tentative_until ??
                                                        '',
                                                })}
                                            </span>
                                            {props.can.manage && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        post(
                                                            BookingActionController.confirm.url(
                                                                item.id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    {t('Confirm')}
                                                </Button>
                                            )}
                                        </div>
                                    }
                                />
                            ))}
                        </Section>
                    )}

                    <Section title={t('In house')} count={props.inHouse.length}>
                        {props.inHouse.map((item) => (
                            <Line key={item.id} item={item} />
                        ))}
                    </Section>
                </div>

                <section className="flex flex-col gap-3">
                    <Heading variant="small" title={t('Rooms tonight')} />
                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-6 lg:grid-cols-10">
                        {props.board.map((room) => {
                            const content = (
                                <>
                                    <div className="flex items-center justify-between font-semibold">
                                        {room.number}
                                        {room.departing && (
                                            <span title={t('Departing today')}>
                                                ↗
                                            </span>
                                        )}
                                    </div>
                                    <div className="truncate text-xs">
                                        {room.state === 'out_of_service'
                                            ? t('Out of service')
                                            : room.state === 'free'
                                              ? t('Free')
                                              : room.guest}
                                    </div>
                                </>
                            );

                            return room.booking_id ? (
                                <Link
                                    key={room.id}
                                    href={BookingController.show(
                                        room.booking_id,
                                    )}
                                    className={cn(
                                        'rounded-lg border p-2',
                                        tones[room.state],
                                    )}
                                >
                                    {content}
                                </Link>
                            ) : (
                                <div
                                    key={room.id}
                                    className={cn(
                                        'rounded-lg border p-2',
                                        tones[room.state],
                                    )}
                                >
                                    {content}
                                </div>
                            );
                        })}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Green: free. Blue: occupied. Amber: arriving today. Grey: out of service. ↗ guest leaving today.',
                        )}
                    </p>
                </section>

                <RegisterCheckForm
                    existing={props.registerCheck}
                    canCheck={props.can.frontdesk}
                />
            </div>
        </>
    );
}

Today.layout = {
    breadcrumbs: [{ title: 'Today', href: TodayController.index() }],
};
