import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import BookingRequestController from '@/actions/App/Domains/BookingRequests/Http/Controllers/BookingRequestController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

export type PendingRequest = {
    id: string;
    reference: string;
    guest: string | null;
    phone: string | null;
    category: string | null;
    room_category_id: string;
    check_in: string;
    check_out: string;
    nights: number;
    rooms: number;
    adults: number;
    children: string;
    breakfast: boolean;
    extra_bed: boolean;
    quoted_total: string;
    current_total: string;
    price_changed: boolean;
    problems: string[];
    received_at: string | null;
    waiting_minutes: number;
    expires_at: string;
    minutes_left: number;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export function RequestCard({
    request,
    categories,
    canOverridePrice,
}: {
    request: PendingRequest;
    categories: { id: string; name: string }[];
    canOverridePrice: boolean;
}) {
    const [panel, setPanel] = useState<'edit' | 'decline' | null>(null);
    const approve = useForm({
        edit: false,
        acknowledge_price: false,
        room_category_id: request.room_category_id,
        check_in: request.check_in,
        check_out: request.check_out,
        adults: request.adults,
        children: request.children,
        rooms: request.rooms,
        breakfast: request.breakfast,
        extra_bed: request.extra_bed,
        override_total: '',
        override_reason: '',
    });
    const decline = useForm({ reason: 'full', note: '' });
    const approveErrors = approve.errors as Record<string, string | undefined>;
    const declineErrors = decline.errors as Record<string, string | undefined>;

    const submitApprove = (event: FormEvent, edit: boolean) => {
        event.preventDefault();
        approve.transform((data) => ({
            ...data,
            edit,
            override_total:
                data.override_total === '' ? null : data.override_total,
            override_reason:
                data.override_reason === '' ? null : data.override_reason,
        }));
        approve.post(BookingRequestController.approve.url(request.id), {
            preserveScroll: true,
        });
    };

    return (
        <div className="flex flex-col gap-2 rounded-xl border p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{request.guest}</span>
                <span className="text-muted-foreground">{request.phone}</span>
                <Badge variant="outline">{request.reference}</Badge>
                <Badge
                    variant={
                        request.minutes_left < 30 ? 'destructive' : 'secondary'
                    }
                >
                    {t('Rooms held until :time', { time: request.expires_at })}
                </Badge>
            </div>
            <div>
                {request.category} × {request.rooms} · {request.check_in} →{' '}
                {request.check_out} (
                {t(':nights night(s)', { nights: request.nights })}) ·{' '}
                {t(':adults adult(s)', { adults: request.adults })}
                {request.children &&
                    ` · ${t('children aged :ages', { ages: request.children })}`}
                {request.breakfast && ` · ${t('breakfast')}`}
                {request.extra_bed && ` · ${t('extra bed')}`}
            </div>
            <div>
                {t('Quoted to the guest: :total', {
                    total: request.quoted_total,
                })}
                {request.price_changed && (
                    <span className="ml-2 text-destructive">
                        {t('Price now: :total', {
                            total: request.current_total,
                        })}
                    </span>
                )}
            </div>
            {request.problems.length > 0 && (
                <p className="text-destructive">{request.problems.join(' ')}</p>
            )}
            <p className="text-xs text-muted-foreground">
                {t('Received :time · waiting :minutes min', {
                    time: request.received_at ?? '',
                    minutes: request.waiting_minutes,
                })}
            </p>

            {panel === null && (
                <form
                    onSubmit={(e) => submitApprove(e, false)}
                    className="flex flex-col gap-2"
                >
                    {request.price_changed && (
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={approve.data.acknowledge_price}
                                onChange={(e) =>
                                    approve.setData(
                                        'acknowledge_price',
                                        e.target.checked,
                                    )
                                }
                            />
                            {t('Approve at the new price :total', {
                                total: request.current_total,
                            })}
                        </label>
                    )}
                    <InputError
                        message={
                            approveErrors.acknowledge_price ??
                            approveErrors.request
                        }
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button size="sm" disabled={approve.processing}>
                            {t('Approve')}
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            variant="outline"
                            onClick={() => setPanel('edit')}
                        >
                            {t('Edit then approve')}
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            variant="ghost"
                            className="text-destructive"
                            onClick={() => setPanel('decline')}
                        >
                            {t('Decline')}
                        </Button>
                    </div>
                </form>
            )}

            {panel === 'edit' && (
                <form
                    onSubmit={(e) => submitApprove(e, true)}
                    className="grid gap-2 rounded-lg border p-3 sm:grid-cols-3"
                >
                    <div className="grid gap-1 sm:col-span-3">
                        <Label htmlFor={`cat-${request.id}`}>
                            {t('Room category')}
                        </Label>
                        <select
                            id={`cat-${request.id}`}
                            className={selectClass}
                            value={approve.data.room_category_id}
                            onChange={(e) =>
                                approve.setData(
                                    'room_category_id',
                                    e.target.value,
                                )
                            }
                        >
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`in-${request.id}`}>
                            {t('Check-in')}
                        </Label>
                        <Input
                            id={`in-${request.id}`}
                            type="date"
                            value={approve.data.check_in}
                            onChange={(e) =>
                                approve.setData('check_in', e.target.value)
                            }
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`out-${request.id}`}>
                            {t('Check-out')}
                        </Label>
                        <Input
                            id={`out-${request.id}`}
                            type="date"
                            value={approve.data.check_out}
                            onChange={(e) =>
                                approve.setData('check_out', e.target.value)
                            }
                        />
                        <InputError message={approveErrors.check_out} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`rooms-${request.id}`}>
                            {t('Rooms')}
                        </Label>
                        <Input
                            id={`rooms-${request.id}`}
                            type="number"
                            min={1}
                            value={approve.data.rooms}
                            onChange={(e) =>
                                approve.setData('rooms', Number(e.target.value))
                            }
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`adults-${request.id}`}>
                            {t('Adults')}
                        </Label>
                        <Input
                            id={`adults-${request.id}`}
                            type="number"
                            min={1}
                            value={approve.data.adults}
                            onChange={(e) =>
                                approve.setData(
                                    'adults',
                                    Number(e.target.value),
                                )
                            }
                        />
                    </div>
                    <div className="grid gap-1 sm:col-span-2">
                        <Label htmlFor={`children-${request.id}`}>
                            {t('Children ages (e.g. 4, 9)')}
                        </Label>
                        <Input
                            id={`children-${request.id}`}
                            value={approve.data.children}
                            onChange={(e) =>
                                approve.setData('children', e.target.value)
                            }
                        />
                    </div>
                    {canOverridePrice && (
                        <>
                            <div className="grid gap-1">
                                <Label htmlFor={`total-${request.id}`}>
                                    {t('Agreed total (optional)')}
                                </Label>
                                <Input
                                    id={`total-${request.id}`}
                                    type="number"
                                    min={0}
                                    step="any"
                                    value={approve.data.override_total}
                                    onChange={(e) =>
                                        approve.setData(
                                            'override_total',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1 sm:col-span-2">
                                <Label htmlFor={`reason-${request.id}`}>
                                    {t('Reason for the different price')}
                                </Label>
                                <Input
                                    id={`reason-${request.id}`}
                                    value={approve.data.override_reason}
                                    onChange={(e) =>
                                        approve.setData(
                                            'override_reason',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={approveErrors.override_reason}
                                />
                            </div>
                        </>
                    )}
                    <label className="flex items-center gap-2 sm:col-span-3">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={approve.data.acknowledge_price}
                            onChange={(e) =>
                                approve.setData(
                                    'acknowledge_price',
                                    e.target.checked,
                                )
                            }
                        />
                        {t(
                            'I accept that the price may differ from the one quoted to the guest',
                        )}
                    </label>
                    <InputError
                        className="sm:col-span-3"
                        message={
                            approveErrors.acknowledge_price ??
                            approveErrors.request ??
                            approveErrors.room_category_id
                        }
                    />
                    <div className="flex gap-2 sm:col-span-3">
                        <Button size="sm" disabled={approve.processing}>
                            {t('Approve with changes')}
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            variant="ghost"
                            onClick={() => setPanel(null)}
                        >
                            {t('Cancel')}
                        </Button>
                    </div>
                </form>
            )}

            {panel === 'decline' && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        decline.post(
                            BookingRequestController.decline.url(request.id),
                            { preserveScroll: true },
                        );
                    }}
                    className="flex flex-col gap-2 rounded-lg border border-destructive/40 p-3"
                >
                    <select
                        aria-label={t('Reason')}
                        className={selectClass}
                        value={decline.data.reason}
                        onChange={(e) =>
                            decline.setData('reason', e.target.value)
                        }
                    >
                        <option value="full">{t('No rooms left')}</option>
                        <option value="dates">
                            {t('These dates are not possible')}
                        </option>
                        <option value="other">{t('Other reason')}</option>
                    </select>
                    {decline.data.reason === 'other' && (
                        <Input
                            aria-label={t('Reason')}
                            value={decline.data.note}
                            onChange={(e) =>
                                decline.setData('note', e.target.value)
                            }
                        />
                    )}
                    <InputError
                        message={declineErrors.note ?? declineErrors.request}
                    />
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="destructive"
                            disabled={decline.processing}
                        >
                            {t('Decline request')}
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            variant="ghost"
                            onClick={() => setPanel(null)}
                        >
                            {t('Cancel')}
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}
