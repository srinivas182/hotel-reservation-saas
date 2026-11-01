import { Head } from '@inertiajs/react';
import BookingController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingController';
import { BookingStatusBadge } from '@/components/bookings/status-badge';
import Heading from '@/components/heading';
import { t } from '@/lib/i18n';

type Booking = {
    id: string;
    reference: string;
    status: string;
    status_label: string;
    channel_label: string;
    external_reference: string | null;
    group_label: string | null;
    category: string | null;
    check_in: string;
    check_out: string;
    nights: number;
    rooms: number;
    adults: number;
    children_ages: (number | null)[];
    breakfast: boolean;
    extra_bed: boolean;
    total: string;
    notes: string | null;
    payment_note: string | null;
    price_overridden: boolean;
    override_reason: string | null;
    tentative_until: string | null;
    guest: {
        name: string | null;
        phone: string | null;
        email: string | null;
        preferred_locale: string | null;
    };
    rooms_list: { position: number; number: string | null }[];
    price: {
        nights: { date: string; rate: string; label: string | null }[];
        lines: { label: string; quantity: number; amount: string }[];
        taxes: { name: string; amount: string; inclusive: boolean }[];
        quoted_total: string;
    };
};

type Props = {
    booking: Booking;
    timeline: {
        event: string;
        description: string;
        by: string | null;
        at: string | null;
    }[];
};

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex justify-between gap-4 py-1">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">{children}</dd>
        </div>
    );
}

export default function ShowBooking({ booking, timeline }: Props) {
    return (
        <>
            <Head title={booking.reference} />
            <div className="grid max-w-5xl gap-6 p-4 lg:grid-cols-[1fr_20rem]">
                <div className="flex flex-col gap-6">
                    <div className="flex flex-wrap items-center gap-3">
                        <Heading
                            title={booking.reference}
                            description={booking.guest.name ?? ''}
                        />
                        <BookingStatusBadge
                            status={booking.status}
                            label={booking.status_label}
                        />
                    </div>

                    {booking.status === 'tentative' &&
                        booking.tentative_until && (
                            <p className="rounded-lg border px-3 py-2 text-sm">
                                {t('Tentative: rooms are held until :time.', {
                                    time: booking.tentative_until,
                                })}
                            </p>
                        )}

                    <section className="rounded-xl border p-4 text-sm">
                        <Heading variant="small" title={t('Stay')} />
                        <dl className="mt-2">
                            <Row label={t('Dates')}>
                                {booking.check_in} → {booking.check_out} (
                                {t(':nights night(s)', {
                                    nights: booking.nights,
                                })}
                                )
                            </Row>
                            <Row label={t('Room category')}>
                                {booking.category} × {booking.rooms}
                            </Row>
                            <Row label={t('Room numbers')}>
                                {booking.rooms_list
                                    .map(
                                        (room) =>
                                            room.number ?? t('not given yet'),
                                    )
                                    .join(', ')}
                            </Row>
                            <Row label={t('Guests')}>
                                {t(':adults adult(s)', {
                                    adults: booking.adults,
                                })}
                                {booking.children_ages.length > 0 &&
                                    `, ${t(':count child(ren)', { count: booking.children_ages.length })}`}
                            </Row>
                            {booking.breakfast && (
                                <Row label={t('Breakfast')}>{t('Yes')}</Row>
                            )}
                            {booking.extra_bed && (
                                <Row label={t('Extra bed')}>{t('Yes')}</Row>
                            )}
                            <Row label={t('Channel')}>
                                {booking.channel_label}
                                {booking.external_reference &&
                                    ` · ${booking.external_reference}`}
                            </Row>
                            {booking.group_label && (
                                <Row label={t('Group')}>
                                    {booking.group_label}
                                </Row>
                            )}
                            {booking.notes && (
                                <Row label={t('Notes')}>{booking.notes}</Row>
                            )}
                            {booking.payment_note && (
                                <Row label={t('Payment or deposit note')}>
                                    {booking.payment_note}
                                </Row>
                            )}
                        </dl>
                    </section>

                    <section className="rounded-xl border p-4 text-sm">
                        <Heading variant="small" title={t('Guest')} />
                        <dl className="mt-2">
                            <Row label={t('Name')}>{booking.guest.name}</Row>
                            <Row label={t('Phone')}>
                                {booking.guest.phone ?? '—'}
                            </Row>
                            <Row label={t('Email')}>
                                {booking.guest.email ?? '—'}
                            </Row>
                        </dl>
                    </section>

                    <section className="flex flex-col gap-2 rounded-xl border p-4 text-sm">
                        <Heading variant="small" title={t('History')} />
                        <ul className="divide-y">
                            {timeline.map((entry, index) => (
                                <li
                                    key={index}
                                    className="flex justify-between gap-4 py-2"
                                >
                                    <span>
                                        {entry.description}
                                        {entry.by && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {entry.by}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {entry.at}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>

                <aside className="flex h-fit flex-col gap-2 rounded-xl border p-4 text-sm">
                    <Heading variant="small" title={t('Price')} />
                    <dl>
                        {booking.price.nights.map((night) => (
                            <Row
                                key={night.date}
                                label={`${night.date}${night.label ? ` · ${night.label}` : ''}`}
                            >
                                {night.rate}
                            </Row>
                        ))}
                    </dl>
                    <dl className="border-t pt-2">
                        {booking.price.lines.map((line) => (
                            <Row
                                key={line.label}
                                label={`${line.label} × ${line.quantity}`}
                            >
                                {line.amount}
                            </Row>
                        ))}
                        {booking.price.taxes.map((tax) => (
                            <Row
                                key={tax.name}
                                label={`${tax.name} ${tax.inclusive ? t('(included)') : t('(added)')}`}
                            >
                                {tax.amount}
                            </Row>
                        ))}
                    </dl>
                    <div className="flex justify-between border-t pt-2 font-medium">
                        <span>{t('Total')}</span>
                        <span>{booking.total}</span>
                    </div>
                    {booking.price_overridden && (
                        <p className="text-xs text-muted-foreground">
                            {t('Price changed from :quoted. Reason: :reason', {
                                quoted: booking.price.quoted_total,
                                reason: booking.override_reason ?? '',
                            })}
                        </p>
                    )}
                </aside>
            </div>
        </>
    );
}

ShowBooking.layout = {
    breadcrumbs: [{ title: 'Bookings', href: BookingController.index() }],
};
