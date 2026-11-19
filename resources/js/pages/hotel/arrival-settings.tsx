import { Head, Link, router, useForm } from '@inertiajs/react';
import { ImagePlus, MapPin, Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Texts = Record<string, string>;
type Extra = { enabled: boolean; price_minor: number | null; label: string };

type Props = {
    locales: string[];
    reminder: { enabled: boolean; time: string };
    hasPin: boolean;
    mapUrl: string | null;
    currency: string;
    settings: {
        arrival_directions: Texts;
        arrival_tip: Texts;
        night_arrival: Texts;
        reception_closes_at: string | null;
        arrival_extras: Record<string, Extra>;
    };
    photoUrl: string | null;
};

const LANGUAGE: Record<string, string> = { fr: 'Français', en: 'English' };

function Localized({
    label,
    hint,
    locales,
    value,
    onChange,
    placeholder,
    error,
}: {
    label: string;
    hint?: string;
    locales: string[];
    value: Texts;
    onChange: (value: Texts) => void;
    placeholder?: string;
    error?: string;
}) {
    return (
        <fieldset className="flex flex-col gap-2">
            <legend className="font-medium">{label}</legend>
            {hint && <p className="text-muted-foreground">{hint}</p>}
            {locales.map((locale) => (
                <label key={locale} className="grid gap-1">
                    {locales.length > 1 && (
                        <span className="text-xs text-muted-foreground">
                            {LANGUAGE[locale] ?? locale}
                        </span>
                    )}
                    <textarea
                        className="min-h-16 rounded-md border bg-transparent px-3 py-2"
                        maxLength={400}
                        placeholder={placeholder}
                        value={value[locale] ?? ''}
                        onChange={(e) =>
                            onChange({ ...value, [locale]: e.target.value })
                        }
                    />
                </label>
            ))}
            <InputError message={error} />
        </fieldset>
    );
}

export default function ArrivalSettings(props: Props) {
    const form = useForm({
        arrival_directions: { ...props.settings.arrival_directions } as Texts,
        arrival_tip: { ...props.settings.arrival_tip } as Texts,
        night_arrival: { ...props.settings.night_arrival } as Texts,
        reception_closes_at: props.settings.reception_closes_at ?? '',
        arrival_extras: props.settings.arrival_extras,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const photo = useForm<{ photo: File | null }>({ photo: null });

    return (
        <>
            <Head title={t('Arrival card')} />
            <div className="flex max-w-3xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('Arrival card')}
                    description={t(
                        'The day before arrival, each confirmed guest gets a card on WhatsApp: their booking, what is left to pay, buttons to confirm, the arrival time, then the map pin.',
                    )}
                />

                <section className="flex flex-col gap-1 rounded-xl border p-4">
                    <p>
                        {props.reminder.enabled
                            ? t('Sent every day at :time.', {
                                  time: props.reminder.time,
                              })
                            : t(
                                  'The card is off: turn on the arrival reminder in AI assistant settings.',
                              )}{' '}
                        <Link
                            href="/hotel/assistant"
                            className="underline underline-offset-4"
                        >
                            {t('AI assistant settings')}
                        </Link>
                    </p>
                    <p className="flex items-center gap-1 text-muted-foreground">
                        <MapPin aria-hidden className="size-4" />
                        {props.hasPin
                            ? t('The map pin comes from your Google Maps link.')
                            : t(
                                  'No map pin yet: add your Google Maps link in the hotel settings.',
                              )}
                    </p>
                </section>

                <form
                    className="flex flex-col gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            reception_closes_at:
                                data.reception_closes_at || null,
                        }));
                        form.put('/hotel/arrival-settings', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Localized
                        label={t('How to find the hotel')}
                        hint={t(
                            'Landmarks, as you would tell a visitor. Sent under the map pin.',
                        )}
                        placeholder={t(
                            'Opposite the Grande Mosquée, blue gate, 100 m after the pharmacy.',
                        )}
                        locales={props.locales}
                        value={form.data.arrival_directions}
                        onChange={(v) => form.setData('arrival_directions', v)}
                        error={errors['arrival_directions.fr']}
                    />
                    <Localized
                        label={t('Transport tip')}
                        hint={t(
                            'Optional: how to come, and what it usually costs.',
                        )}
                        placeholder={t(
                            'From the bus station, a moto-taxi costs about 500 F CFA: say “First Class Lodge, quartier Résidentiel”.',
                        )}
                        locales={props.locales}
                        value={form.data.arrival_tip}
                        onChange={(v) => form.setData('arrival_tip', v)}
                    />

                    <fieldset className="flex flex-col gap-2">
                        <legend className="font-medium">
                            {t('Late arrivals')}
                        </legend>
                        <label className="flex flex-wrap items-center gap-2">
                            {t('Reception closes at')}
                            <Input
                                type="time"
                                className="w-32"
                                value={form.data.reception_closes_at}
                                onChange={(e) =>
                                    form.setData(
                                        'reception_closes_at',
                                        e.target.value,
                                    )
                                }
                            />
                            <span className="text-muted-foreground">
                                {t('(empty: open all night)')}
                            </span>
                        </label>
                        <InputError message={errors.reception_closes_at} />
                    </fieldset>
                    {form.data.reception_closes_at && (
                        <Localized
                            label={t('What late guests should do')}
                            hint={t(
                                'Sent to guests who choose an arrival time after reception closes.',
                            )}
                            placeholder={t(
                                'Ring the night bell at the gate; the guard has your key.',
                            )}
                            locales={props.locales}
                            value={form.data.night_arrival}
                            onChange={(v) => form.setData('night_arrival', v)}
                        />
                    )}

                    <fieldset className="flex flex-col gap-2">
                        <legend className="font-medium">
                            {t('What guests can ask for')}
                        </legend>
                        <p className="text-muted-foreground">
                            {t(
                                'Offered as buttons once the guest confirms. Each request alerts reception.',
                            )}
                        </p>
                        {Object.entries(form.data.arrival_extras).map(
                            ([key, extra]) => (
                                <div
                                    key={key}
                                    className="flex flex-wrap items-center gap-3"
                                >
                                    <label className="flex min-w-48 items-center gap-2">
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            checked={extra.enabled}
                                            onChange={(e) =>
                                                form.setData('arrival_extras', {
                                                    ...form.data.arrival_extras,
                                                    [key]: {
                                                        ...extra,
                                                        enabled:
                                                            e.target.checked,
                                                    },
                                                })
                                            }
                                        />
                                        {extra.label}
                                    </label>
                                    {extra.enabled && (
                                        <label className="flex items-center gap-2">
                                            <span className="text-muted-foreground">
                                                {t('Price (:currency)', {
                                                    currency: props.currency,
                                                })}
                                            </span>
                                            <Input
                                                className="w-28"
                                                inputMode="numeric"
                                                placeholder={t('free')}
                                                value={
                                                    extra.price_minor === null
                                                        ? ''
                                                        : String(
                                                              extra.price_minor,
                                                          )
                                                }
                                                onChange={(e) => {
                                                    const digits =
                                                        e.target.value.replace(
                                                            /\D/g,
                                                            '',
                                                        );
                                                    form.setData(
                                                        'arrival_extras',
                                                        {
                                                            ...form.data
                                                                .arrival_extras,
                                                            [key]: {
                                                                ...extra,
                                                                price_minor:
                                                                    digits ===
                                                                    ''
                                                                        ? null
                                                                        : Number(
                                                                              digits,
                                                                          ),
                                                            },
                                                        },
                                                    );
                                                }}
                                            />
                                        </label>
                                    )}
                                </div>
                            ),
                        )}
                    </fieldset>

                    <Button
                        type="submit"
                        className="w-fit"
                        disabled={form.processing}
                    >
                        {t('Save')}
                    </Button>
                </form>

                <section className="flex flex-col gap-2 rounded-xl border p-4">
                    <h2 className="font-medium">
                        {t('Photo of the entrance')}
                    </h2>
                    <p className="text-muted-foreground">
                        {t(
                            'Shown at the top of the card, so guests recognise the place (JPEG or PNG, up to 3 MB).',
                        )}
                    </p>
                    {props.photoUrl && (
                        <img
                            src={props.photoUrl}
                            alt={t('Photo of the entrance')}
                            className="max-h-48 w-fit rounded-lg border object-cover"
                        />
                    )}
                    <form
                        className="flex flex-wrap items-center gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            photo.post('/hotel/arrival-settings/photo', {
                                preserveScroll: true,
                                forceFormData: true,
                                onSuccess: () => photo.reset(),
                            });
                        }}
                    >
                        <Input
                            type="file"
                            accept="image/jpeg,image/png"
                            aria-label={t('Choose a photo')}
                            className="w-fit"
                            onChange={(e) =>
                                photo.setData(
                                    'photo',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={!photo.data.photo || photo.processing}
                        >
                            <ImagePlus aria-hidden />
                            {t('Upload')}
                        </Button>
                        {props.photoUrl && (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(
                                        '/hotel/arrival-settings/photo',
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Trash2 aria-hidden />
                                {t('Remove')}
                            </Button>
                        )}
                    </form>
                    <InputError message={photo.errors.photo} />
                </section>
            </div>
        </>
    );
}
