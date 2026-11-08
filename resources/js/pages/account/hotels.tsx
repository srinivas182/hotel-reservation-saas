import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AccountHotelsController from '@/actions/App/Domains/Platform/Http/Controllers/AccountHotelsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type HotelRow = {
    id: string;
    name: string;
    country: string;
    status: string;
    closure: {
        closed_at: string;
        delete_after: string;
        export: boolean;
    } | null;
};
type Props = {
    hotels: HotelRow[];
    countries: { code: string; name: string }[];
    hasTwoFactor: boolean;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function CloseForm({
    hotel,
    hasTwoFactor,
}: {
    hotel: HotelRow;
    hasTwoFactor: boolean;
}) {
    const form = useForm({ password: '', reason: '', confirm: '' });
    const errors = form.errors as Record<string, string | undefined>;

    if (!hasTwoFactor) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('Turn on two-step login before closing a hotel.')}
            </p>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(AccountHotelsController.close.url(hotel.id), {
                    preserveScroll: true,
                });
            }}
            className="grid gap-2 rounded-lg border border-destructive p-3 text-sm"
        >
            <p>
                {t(
                    'Closing stops the assistant, WhatsApp and invoices, and removes your team’s access. You can download all data or reopen for 90 days; then the data is deleted.',
                )}
            </p>
            <Input
                placeholder={t('Reason (optional)')}
                value={form.data.reason}
                onChange={(e) => form.setData('reason', e.target.value)}
            />
            <Input
                placeholder={t('Type the hotel name: :name', {
                    name: hotel.name,
                })}
                value={form.data.confirm}
                onChange={(e) => form.setData('confirm', e.target.value)}
            />
            <InputError message={errors.confirm} />
            <Input
                type="password"
                placeholder={t('Your password')}
                value={form.data.password}
                onChange={(e) => form.setData('password', e.target.value)}
            />
            <InputError message={errors.password} />
            <div>
                <Button
                    variant="destructive"
                    size="sm"
                    disabled={form.processing}
                >
                    {t('Close this hotel')}
                </Button>
            </div>
        </form>
    );
}

export default function AccountHotels({
    hotels,
    countries,
    hasTwoFactor,
}: Props) {
    const [closing, setClosing] = useState<string | null>(null);
    const add = useForm({
        hotel_name: '',
        country_code:
            countries.find((c) => c.code === 'CI')?.code ??
            countries[0]?.code ??
            '',
        city: '',
        rooms: 10,
    });
    const addErrors = add.errors as Record<string, string | undefined>;

    return (
        <>
            <Head title={t('My hotels')} />
            <div className="flex max-w-4xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('My hotels')}
                    description={t(
                        'Hotels you own. Each has its own plan, data and WhatsApp number.',
                    )}
                />
                <ul className="divide-y rounded-xl border">
                    {hotels.length === 0 && (
                        <li className="px-3 py-2 text-muted-foreground">
                            {t('You do not own a hotel yet.')}
                        </li>
                    )}
                    {hotels.map((h) => (
                        <li
                            key={h.id}
                            className="flex flex-col gap-2 px-3 py-2"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{h.name}</span>
                                <span className="text-muted-foreground">
                                    {h.country}
                                </span>
                                <Badge
                                    variant={
                                        h.status === 'closed'
                                            ? 'destructive'
                                            : 'secondary'
                                    }
                                >
                                    {h.status}
                                </Badge>
                                {h.closure ? (
                                    <span className="ml-auto flex flex-wrap items-center gap-2">
                                        <span className="text-muted-foreground">
                                            {t('Data deleted on :date', {
                                                date: h.closure.delete_after,
                                            })}
                                        </span>
                                        {h.closure.export && (
                                            <a
                                                href={AccountHotelsController.export.url(
                                                    h.id,
                                                )}
                                                className="underline"
                                            >
                                                {t('Download all data')}
                                            </a>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    AccountHotelsController.reopen.url(
                                                        h.id,
                                                    ),
                                                )
                                            }
                                        >
                                            {t('Reopen')}
                                        </Button>
                                    </span>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="ml-auto"
                                        onClick={() =>
                                            setClosing(
                                                closing === h.id ? null : h.id,
                                            )
                                        }
                                    >
                                        {closing === h.id
                                            ? t('Cancel')
                                            : t('Close hotel…')}
                                    </Button>
                                )}
                            </div>
                            {closing === h.id && !h.closure && (
                                <CloseForm
                                    hotel={h}
                                    hasTwoFactor={hasTwoFactor}
                                />
                            )}
                        </li>
                    ))}
                </ul>

                <section className="flex flex-col gap-2">
                    <Heading
                        variant="small"
                        title={t('Add a hotel')}
                        description={t(
                            'A new hotel with its own trial and setup steps.',
                        )}
                    />
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            add.post(AccountHotelsController.store.url());
                        }}
                        className="grid gap-2 rounded-xl border p-3 sm:grid-cols-4"
                    >
                        <Input
                            aria-label={t('Hotel name')}
                            placeholder={t('Hotel name')}
                            value={add.data.hotel_name}
                            onChange={(e) =>
                                add.setData('hotel_name', e.target.value)
                            }
                        />
                        <select
                            aria-label={t('Country')}
                            className={selectClass}
                            value={add.data.country_code}
                            onChange={(e) =>
                                add.setData('country_code', e.target.value)
                            }
                        >
                            {countries.map((c) => (
                                <option key={c.code} value={c.code}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                        <Input
                            aria-label={t('City')}
                            placeholder={t('City')}
                            value={add.data.city}
                            onChange={(e) =>
                                add.setData('city', e.target.value)
                            }
                        />
                        <Input
                            aria-label={t('Number of rooms')}
                            type="number"
                            min={1}
                            value={add.data.rooms}
                            onChange={(e) =>
                                add.setData('rooms', Number(e.target.value))
                            }
                        />
                        <InputError
                            message={
                                addErrors.hotel_name ??
                                addErrors.city ??
                                addErrors.country_code
                            }
                        />
                        <div>
                            <Button size="sm" disabled={add.processing}>
                                {t('Add a hotel')}
                            </Button>
                        </div>
                    </form>
                </section>
            </div>
        </>
    );
}

AccountHotels.layout = {
    breadcrumbs: [
        { title: 'My hotels', href: AccountHotelsController.index() },
    ],
};
