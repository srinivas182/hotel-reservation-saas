import { Head, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import HotelSettingsController from '@/actions/App/Domains/Hotel/Http/Controllers/HotelSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { EmailIdentityForm } from '@/components/hotel/email-identity-form';
import type { EmailIdentity } from '@/components/hotel/email-identity-form';

type Interval = { from: string; to: string };
type StaffedHours = Record<string, Interval[]>;

type Props = {
    emailIdentity: EmailIdentity;
    hotel: {
        name: string;
        legal_name: string | null;
        timezone: string;
        currency: string;
        default_locale: string;
    };
    settings: {
        phone_e164: string | null;
        whatsapp_display_e164: string | null;
        email: string | null;
        address_line1: string | null;
        address_line2: string | null;
        city: string | null;
        map_url: string | null;
        check_in_time: string;
        check_out_time: string;
        late_checkout_until: string | null;
        booking_prefix: string | null;
        default_booking_prefix: string;
        tentative_hours: number;
        staffed_hours: StaffedHours;
        enabled_locales: string[];
    };
    options: {
        locales: Record<string, string>;
        timezones: string[];
        days: string[];
    };
    preview: { datetime: string; money: string };
    canManage: boolean;
};

const dayNames: Record<string, string> = {
    mon: 'Monday',
    tue: 'Tuesday',
    wed: 'Wednesday',
    thu: 'Thursday',
    fri: 'Friday',
    sat: 'Saturday',
    sun: 'Sunday',
};

const selectClass =
    'mt-1 flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50';

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

export default function HotelSettingsPage({
    hotel,
    settings,
    options,
    preview,
    canManage,
    emailIdentity,
}: Props) {
    const form = useForm({
        name: hotel.name,
        legal_name: hotel.legal_name ?? '',
        timezone: hotel.timezone,
        currency: hotel.currency,
        default_locale: hotel.default_locale,
        phone_e164: settings.phone_e164 ?? '',
        whatsapp_display_e164: settings.whatsapp_display_e164 ?? '',
        email: settings.email ?? '',
        address_line1: settings.address_line1 ?? '',
        address_line2: settings.address_line2 ?? '',
        city: settings.city ?? '',
        map_url: settings.map_url ?? '',
        check_in_time: settings.check_in_time,
        check_out_time: settings.check_out_time,
        late_checkout_until: settings.late_checkout_until ?? '',
        booking_prefix: settings.booking_prefix ?? '',
        tentative_hours: settings.tentative_hours,
        staffed_hours: settings.staffed_hours as StaffedHours,
        enabled_locales: settings.enabled_locales,
    });

    const { data, setData, errors, processing } = form;
    const error = (key: string) =>
        (errors as Record<string, string | undefined>)[key];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            late_checkout_until: values.late_checkout_until || null,
        }));
        form.put(HotelSettingsController.update.url(), {
            preserveScroll: true,
        });
    };

    const setIntervals = (day: string, intervals: Interval[]) =>
        setData('staffed_hours', { ...data.staffed_hours, [day]: intervals });

    const toggleLocale = (code: string, checked: boolean) =>
        setData(
            'enabled_locales',
            checked
                ? [...data.enabled_locales, code]
                : data.enabled_locales.filter((c) => c !== code),
        );

    return (
        <>
            <Head title={t('Hotel settings')} />
            <form
                onSubmit={submit}
                className="flex max-w-3xl flex-col gap-10 p-4"
            >
                <fieldset
                    disabled={!canManage}
                    className="flex flex-col gap-10"
                >
                    <section className="flex flex-col gap-4">
                        <Heading
                            title={t('Hotel settings')}
                            description={t(
                                'Details used by your team, the WhatsApp assistant and guest messages.',
                            )}
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id="name"
                                label={t('Hotel name')}
                                error={error('name')}
                            >
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                id="legal_name"
                                label={t('Legal name')}
                                error={error('legal_name')}
                            >
                                <Input
                                    id="legal_name"
                                    value={data.legal_name}
                                    onChange={(e) =>
                                        setData('legal_name', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    </section>

                    <section className="flex flex-col gap-4">
                        <Heading variant="small" title={t('Contact')} />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id="phone_e164"
                                label={t('Phone')}
                                error={error('phone_e164')}
                            >
                                <Input
                                    id="phone_e164"
                                    value={data.phone_e164}
                                    placeholder="+2250700000000"
                                    onChange={(e) =>
                                        setData('phone_e164', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="whatsapp_display_e164"
                                label={t('WhatsApp number shown to guests')}
                                error={error('whatsapp_display_e164')}
                            >
                                <Input
                                    id="whatsapp_display_e164"
                                    value={data.whatsapp_display_e164}
                                    placeholder="+2250700000000"
                                    onChange={(e) =>
                                        setData(
                                            'whatsapp_display_e164',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                id="email"
                                label={t('Email')}
                                error={error('email')}
                            >
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) =>
                                        setData('email', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="city"
                                label={t('City')}
                                error={error('city')}
                            >
                                <Input
                                    id="city"
                                    value={data.city}
                                    onChange={(e) =>
                                        setData('city', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="address_line1"
                                label={t('Address')}
                                error={error('address_line1')}
                            >
                                <Input
                                    id="address_line1"
                                    value={data.address_line1}
                                    onChange={(e) =>
                                        setData('address_line1', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="address_line2"
                                label={t('Address (line 2)')}
                                error={error('address_line2')}
                            >
                                <Input
                                    id="address_line2"
                                    value={data.address_line2}
                                    onChange={(e) =>
                                        setData('address_line2', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                        <Field
                            id="map_url"
                            label={t('Map link (https)')}
                            error={error('map_url')}
                        >
                            <Input
                                id="map_url"
                                type="url"
                                value={data.map_url}
                                onChange={(e) =>
                                    setData('map_url', e.target.value)
                                }
                            />
                        </Field>
                    </section>

                    <section className="flex flex-col gap-4">
                        <Heading variant="small" title={t('Operations')} />
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field
                                id="check_in_time"
                                label={t('Check-in from')}
                                error={error('check_in_time')}
                            >
                                <Input
                                    id="check_in_time"
                                    type="time"
                                    value={data.check_in_time}
                                    onChange={(e) =>
                                        setData('check_in_time', e.target.value)
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                id="check_out_time"
                                label={t('Check-out by')}
                                error={error('check_out_time')}
                            >
                                <Input
                                    id="check_out_time"
                                    type="time"
                                    value={data.check_out_time}
                                    onChange={(e) =>
                                        setData(
                                            'check_out_time',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                id="late_checkout_until"
                                label={t('Latest late check-out')}
                                error={error('late_checkout_until')}
                            >
                                <Input
                                    id="late_checkout_until"
                                    type="time"
                                    value={data.late_checkout_until}
                                    onChange={(e) =>
                                        setData(
                                            'late_checkout_until',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field
                                id="booking_prefix"
                                label={t('Booking reference prefix')}
                                error={error('booking_prefix')}
                            >
                                <Input
                                    id="booking_prefix"
                                    maxLength={6}
                                    placeholder={
                                        settings.default_booking_prefix
                                    }
                                    value={data.booking_prefix}
                                    onChange={(e) =>
                                        setData(
                                            'booking_prefix',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                id="tentative_hours"
                                label={t(
                                    'Tentative bookings hold rooms for (hours)',
                                )}
                                error={error('tentative_hours')}
                            >
                                <Input
                                    id="tentative_hours"
                                    type="number"
                                    min={1}
                                    max={720}
                                    value={data.tentative_hours}
                                    onChange={(e) =>
                                        setData(
                                            'tentative_hours',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </Field>
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label>
                                {t(
                                    'Staffed hours (when reception answers requests)',
                                )}
                            </Label>
                            <div className="divide-y rounded-xl border">
                                {options.days.map((day) => {
                                    const intervals =
                                        data.staffed_hours[day] ?? [];

                                    return (
                                        <div
                                            key={day}
                                            className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm"
                                        >
                                            <span className="w-24 font-medium">
                                                {dayNames[day]}
                                            </span>
                                            {intervals.length === 0 && (
                                                <span className="text-muted-foreground">
                                                    {t('Closed')}
                                                </span>
                                            )}
                                            {intervals.map(
                                                (interval, index) => (
                                                    <span
                                                        key={index}
                                                        className="flex items-center gap-1"
                                                    >
                                                        <Input
                                                            type="time"
                                                            aria-label={`${dayNames[day]} from`}
                                                            className="h-8 w-28"
                                                            value={
                                                                interval.from
                                                            }
                                                            onChange={(e) =>
                                                                setIntervals(
                                                                    day,
                                                                    intervals.map(
                                                                        (
                                                                            it,
                                                                            i,
                                                                        ) =>
                                                                            i ===
                                                                            index
                                                                                ? {
                                                                                      ...it,
                                                                                      from: e
                                                                                          .target
                                                                                          .value,
                                                                                  }
                                                                                : it,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                        –
                                                        <Input
                                                            type="time"
                                                            aria-label={`${dayNames[day]} to`}
                                                            className="h-8 w-28"
                                                            value={interval.to}
                                                            onChange={(e) =>
                                                                setIntervals(
                                                                    day,
                                                                    intervals.map(
                                                                        (
                                                                            it,
                                                                            i,
                                                                        ) =>
                                                                            i ===
                                                                            index
                                                                                ? {
                                                                                      ...it,
                                                                                      to: e
                                                                                          .target
                                                                                          .value,
                                                                                  }
                                                                                : it,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={t(
                                                                'Remove period',
                                                            )}
                                                            onClick={() =>
                                                                setIntervals(
                                                                    day,
                                                                    intervals.filter(
                                                                        (
                                                                            _,
                                                                            i,
                                                                        ) =>
                                                                            i !==
                                                                            index,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            <X />
                                                        </Button>
                                                    </span>
                                                ),
                                            )}
                                            {intervals.length < 3 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setIntervals(day, [
                                                            ...intervals,
                                                            {
                                                                from: '08:00',
                                                                to: '20:00',
                                                            },
                                                        ])
                                                    }
                                                >
                                                    <Plus /> {t('Add')}
                                                </Button>
                                            )}
                                            <InputError
                                                className="w-full"
                                                message={
                                                    error(
                                                        `staffed_hours.${day}.0.to`,
                                                    ) ??
                                                    error(
                                                        `staffed_hours.${day}.1.to`,
                                                    )
                                                }
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </section>

                    <section className="flex flex-col gap-4">
                        <Heading variant="small" title={t('Regional')} />
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field
                                id="timezone"
                                label={t('Time zone')}
                                error={error('timezone')}
                            >
                                <Input
                                    id="timezone"
                                    list="timezones"
                                    value={data.timezone}
                                    onChange={(e) =>
                                        setData('timezone', e.target.value)
                                    }
                                    required
                                />
                                <datalist id="timezones">
                                    {options.timezones.map((zone) => (
                                        <option key={zone} value={zone} />
                                    ))}
                                </datalist>
                            </Field>
                            <Field
                                id="currency"
                                label={t('Currency')}
                                error={error('currency')}
                            >
                                <Input
                                    id="currency"
                                    maxLength={3}
                                    value={data.currency}
                                    onChange={(e) =>
                                        setData(
                                            'currency',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                id="default_locale"
                                label={t('Default language')}
                                error={error('default_locale')}
                            >
                                <select
                                    id="default_locale"
                                    className={selectClass}
                                    value={data.default_locale}
                                    onChange={(e) =>
                                        setData(
                                            'default_locale',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {Object.entries(options.locales).map(
                                        ([code, label]) => (
                                            <option key={code} value={code}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Field>
                        </div>
                        <div className="grid gap-2">
                            <Label>{t('Enabled languages')}</Label>
                            <div className="flex flex-wrap gap-4">
                                {Object.entries(options.locales).map(
                                    ([code, label]) => (
                                        <label
                                            key={code}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                className="size-4"
                                                checked={data.enabled_locales.includes(
                                                    code,
                                                )}
                                                onChange={(e) =>
                                                    toggleLocale(
                                                        code,
                                                        e.target.checked,
                                                    )
                                                }
                                            />
                                            {label}
                                        </label>
                                    ),
                                )}
                            </div>
                            <InputError message={error('enabled_locales')} />
                        </div>
                        <p className="rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
                            {t('Preview (saved settings):')} {preview.datetime}{' '}
                            · {preview.money}
                        </p>
                    </section>

                    {canManage && (
                        <div>
                            <Button disabled={processing}>
                                {t('Save settings')}
                            </Button>
                        </div>
                    )}
                </fieldset>
            </form>
            <div className="max-w-3xl p-4 pt-0">
                <div className="border-t pt-10">
                    <EmailIdentityForm
                        identity={emailIdentity}
                        canManage={canManage}
                    />
                </div>
            </div>
        </>
    );
}

HotelSettingsPage.layout = {
    breadcrumbs: [
        { title: 'Hotel settings', href: HotelSettingsController.edit() },
    ],
};
