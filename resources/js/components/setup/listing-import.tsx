import { router, useForm, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ListingImportController from '@/actions/App/Domains/Onboarding/Http/Controllers/ListingImportController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';

type Item<T> = { value: T; sure: boolean };
type Pin = { latitude: number; longitude: number };
type ProfileKey =
    | 'name'
    | 'address_line1'
    | 'city'
    | 'check_in_time'
    | 'check_out_time'
    | 'location';
type DraftRoom = {
    name: string;
    count: number | null;
    adults: number;
    price: number | null;
    sure: boolean;
    exists: boolean;
};
type DraftEntry = {
    locale: string;
    section: string;
    question: string | null;
    content: string;
    sure: boolean;
};

export type ListingState = {
    id: string;
    status: 'processing' | 'ready' | 'failed';
    error: string | null;
    notes: string[];
    sources: string[];
    currency: string;
    profile: Partial<Record<ProfileKey, Item<string | Pin>>>;
    current: Record<ProfileKey, string | Pin | null>;
    rooms: DraftRoom[];
    entries: DraftEntry[];
    can: { rooms: boolean; rates: boolean; pack: boolean };
};

const HIDE_KEY = 'setup.listing.hidden';

function labels(): Record<ProfileKey, string> {
    return {
        name: t('Hotel name'),
        address_line1: t('Address'),
        city: t('City'),
        check_in_time: t('Check-in from'),
        check_out_time: t('Check-out by'),
        location: t('Map pin'),
    };
}

function show(value: string | Pin | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return typeof value === 'string'
        ? value
        : `${value.latitude.toFixed(5)}, ${value.longitude.toFixed(5)}`;
}

function CheckThis() {
    return (
        <Badge variant="outline" className="border-amber-500 text-amber-700">
            {t('check this')}
        </Badge>
    );
}

/**
 * Sources form: website, Google Maps link, pasted text, photos or PDFs.
 */
function SourcesForm({ onSkip }: { onSkip?: () => void }) {
    const form = useForm<{
        website_url: string;
        maps_url: string;
        pasted_text: string;
        files: File[];
    }>({ website_url: '', maps_url: '', pasted_text: '', files: [] });
    const errors = form.errors as Record<string, string | undefined>;
    const fileError = Object.entries(errors).find(([k]) =>
        k.startsWith('files'),
    )?.[1];

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(ListingImportController.store.url(), {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
            className="flex flex-col gap-3 text-sm"
        >
            <p className="text-muted-foreground">
                {t(
                    'Give us any of these and we fill in what we can: your rooms, prices, times and the answers for guests. You check everything before it is used.',
                )}
            </p>
            <label className="grid gap-1">
                <span className="font-medium">{t('Your website')}</span>
                <Input
                    type="url"
                    inputMode="url"
                    placeholder="https://"
                    value={form.data.website_url}
                    onChange={(e) =>
                        form.setData('website_url', e.target.value)
                    }
                />
                <InputError message={errors.website_url} />
            </label>
            <label className="grid gap-1">
                <span className="font-medium">
                    {t('Your Google Maps link')}
                </span>
                <span className="text-xs text-muted-foreground">
                    {t(
                        'In Google Maps, open your hotel, press Share and copy the link.',
                    )}
                </span>
                <Input
                    type="url"
                    inputMode="url"
                    placeholder="https://maps.app.goo.gl/…"
                    value={form.data.maps_url}
                    onChange={(e) => form.setData('maps_url', e.target.value)}
                />
                <InputError message={errors.maps_url} />
            </label>
            <label className="grid gap-1">
                <span className="font-medium">
                    {t('Text about your hotel')}
                </span>
                <span className="text-xs text-muted-foreground">
                    {t(
                        'Copy the description from your Booking.com, Google or Facebook page and paste it here.',
                    )}
                </span>
                <textarea
                    className="min-h-24 rounded-md border border-input bg-transparent px-3 py-2"
                    maxLength={20000}
                    value={form.data.pasted_text}
                    onChange={(e) =>
                        form.setData('pasted_text', e.target.value)
                    }
                />
                <InputError message={errors.pasted_text} />
            </label>
            <label className="grid gap-1">
                <span className="font-medium">
                    {t('Photos of your rate card or brochure')}
                </span>
                <span className="text-xs text-muted-foreground">
                    {t('Up to 5 photos or PDFs, 10 MB each.')}
                </span>
                <input
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    className="text-sm"
                    onChange={(e) =>
                        form.setData(
                            'files',
                            Array.from(e.target.files ?? []).slice(0, 5),
                        )
                    }
                />
                <InputError message={fileError} />
            </label>
            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {t('Read it for me')}
                </Button>
                {onSkip && (
                    <Button type="button" variant="ghost" onClick={onSkip}>
                        {t("Skip, I'll type it")}
                    </Button>
                )}
            </div>
        </form>
    );
}

/**
 * The review screen: every item found, with a tick box. Nothing is used
 * until the owner presses "Use these".
 */
function Review({ listing }: { listing: ListingState }) {
    const names = labels();
    const keys = Object.keys(listing.profile) as ProfileKey[];
    const form = useForm({
        profile: keys.filter(
            (k) =>
                listing.profile[k]?.sure === true &&
                (listing.current[k] === null || listing.current[k] === ''),
        ),
        rooms: listing.rooms.map((r) => ({
            use: !r.exists,
            name: r.name,
            count: r.count ?? 1,
            adults: r.adults,
            price: r.price,
        })),
        entries: listing.entries.flatMap((e, i) => (e.sure ? [i] : [])),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const toggle = <T,>(list: T[], value: T): T[] =>
        list.includes(value)
            ? list.filter((v) => v !== value)
            : [...list, value];
    const chosen =
        form.data.profile.length +
        form.data.rooms.filter((r) => r.use).length +
        form.data.entries.length;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => ({
                    profile: data.profile,
                    rooms: data.rooms.flatMap((r, index) =>
                        r.use && listing.can.rooms
                            ? [
                                  {
                                      index,
                                      name: r.name,
                                      count: r.count,
                                      adults: r.adults,
                                      price: listing.can.rates ? r.price : null,
                                  },
                              ]
                            : [],
                    ),
                    entries: listing.can.pack ? data.entries : [],
                }));
                form.post(ListingImportController.apply.url(listing.id), {
                    preserveScroll: true,
                });
            }}
            className="flex flex-col gap-4 text-sm"
        >
            <p className="font-medium">{t("Here's what we found")}</p>
            <p className="text-xs text-muted-foreground">
                {t(
                    'Tick what is right. Items marked “check this” were unclear: read them carefully. Nothing is saved until you press “Use these”.',
                )}
            </p>

            {keys.length > 0 && (
                <fieldset className="flex flex-col gap-2">
                    <legend className="mb-1 font-medium">
                        {t('Hotel details')}
                    </legend>
                    {keys.map((key) => {
                        const item = listing.profile[key];

                        return (
                            <label
                                key={key}
                                className="flex items-start gap-3 rounded-lg border p-2"
                            >
                                <input
                                    type="checkbox"
                                    className="mt-1 size-5"
                                    checked={form.data.profile.includes(key)}
                                    onChange={() =>
                                        form.setData(
                                            'profile',
                                            toggle(form.data.profile, key),
                                        )
                                    }
                                />
                                <span className="flex flex-1 flex-col">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="text-xs text-muted-foreground">
                                            {names[key]}
                                        </span>
                                        {item?.sure === false && <CheckThis />}
                                    </span>
                                    <span className="font-medium">
                                        {show(item?.value)}
                                    </span>
                                    {listing.current[key] !== null &&
                                        listing.current[key] !== '' && (
                                            <span className="text-xs text-muted-foreground">
                                                {t('Now: :value', {
                                                    value: show(
                                                        listing.current[key],
                                                    ),
                                                })}
                                            </span>
                                        )}
                                </span>
                            </label>
                        );
                    })}
                </fieldset>
            )}

            {listing.rooms.length > 0 && listing.can.rooms && (
                <fieldset className="flex flex-col gap-2">
                    <legend className="mb-1 font-medium">
                        {t('Rooms and prices')}
                    </legend>
                    {form.data.rooms.map((room, i) => {
                        const found = listing.rooms[i];
                        const set = (patch: Partial<typeof room>) =>
                            form.setData(
                                'rooms',
                                form.data.rooms.map((r, j) =>
                                    j === i ? { ...r, ...patch } : r,
                                ),
                            );

                        return (
                            <div
                                key={i}
                                className="flex flex-col gap-2 rounded-lg border p-2"
                            >
                                <label className="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        className="size-5"
                                        aria-label={t('Use this room type')}
                                        checked={room.use}
                                        onChange={() => set({ use: !room.use })}
                                    />
                                    <Input
                                        aria-label={t('Category')}
                                        className="w-48"
                                        value={room.name}
                                        maxLength={60}
                                        onChange={(e) =>
                                            set({ name: e.target.value })
                                        }
                                    />
                                    {(found.sure === false ||
                                        found.count === null) && <CheckThis />}
                                    {found.exists && (
                                        <span className="text-xs text-muted-foreground">
                                            {t(
                                                'You already have this category',
                                            )}
                                        </span>
                                    )}
                                </label>
                                <div className="flex flex-wrap items-center gap-2 pl-8">
                                    <label className="flex items-center gap-1">
                                        <Input
                                            type="number"
                                            min={1}
                                            max={500}
                                            className="w-20"
                                            value={room.count}
                                            onChange={(e) =>
                                                set({
                                                    count: Math.max(
                                                        1,
                                                        Number(e.target.value),
                                                    ),
                                                })
                                            }
                                        />
                                        <span>{t('rooms')}</span>
                                    </label>
                                    <label className="flex items-center gap-1">
                                        <Input
                                            type="number"
                                            min={1}
                                            max={10}
                                            className="w-16"
                                            value={room.adults}
                                            onChange={(e) =>
                                                set({
                                                    adults: Math.max(
                                                        1,
                                                        Number(e.target.value),
                                                    ),
                                                })
                                            }
                                        />
                                        <span>{t('adults max')}</span>
                                    </label>
                                    {listing.can.rates && (
                                        <label className="flex items-center gap-1">
                                            <Input
                                                type="number"
                                                min={1}
                                                className="w-28"
                                                value={room.price ?? ''}
                                                onChange={(e) =>
                                                    set({
                                                        price:
                                                            e.target.value ===
                                                            ''
                                                                ? null
                                                                : Number(
                                                                      e.target
                                                                          .value,
                                                                  ),
                                                    })
                                                }
                                            />
                                            <span>
                                                {t(':currency per night', {
                                                    currency: listing.currency,
                                                })}
                                            </span>
                                        </label>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </fieldset>
            )}

            {listing.entries.length > 0 && listing.can.pack && (
                <fieldset className="flex flex-col gap-2">
                    <legend className="mb-1 font-medium">
                        {t('Answers for guests')}
                    </legend>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'These become drafts in your information pack. You publish the pack yourself.',
                        )}
                    </p>
                    {listing.entries.map((entry, i) => (
                        <label
                            key={i}
                            className="flex items-start gap-3 rounded-lg border p-2"
                        >
                            <input
                                type="checkbox"
                                className="mt-1 size-5"
                                checked={form.data.entries.includes(i)}
                                onChange={() =>
                                    form.setData(
                                        'entries',
                                        toggle(form.data.entries, i),
                                    )
                                }
                            />
                            <span className="flex flex-1 flex-col gap-0.5">
                                <span className="flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">
                                        {entry.locale.toUpperCase()}
                                    </Badge>
                                    {entry.question && (
                                        <span className="font-medium">
                                            {entry.question}
                                        </span>
                                    )}
                                    {!entry.sure && <CheckThis />}
                                </span>
                                <span>{entry.content}</span>
                            </span>
                        </label>
                    ))}
                </fieldset>
            )}

            <InputError message={errors.listing} />
            <div className="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    disabled={form.processing || chosen === 0}
                >
                    {form.processing && <Spinner />}
                    {t('Use these (:count)', { count: chosen })}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() =>
                        router.post(
                            ListingImportController.discard.url(listing.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    {t('Discard')}
                </Button>
            </div>
        </form>
    );
}

/**
 * "Start from what you already have" (Sprint A), at the top of Setup.
 */
export function ListingImport({ listing }: { listing: ListingState | null }) {
    const [hidden, setHidden] = useState(false);
    const [again, setAgain] = useState(false);
    const processing = listing?.status === 'processing';
    const poll = usePoll(3000, { only: ['listing'] }, { autoStart: false });

    useEffect(() => {
        try {
            setHidden(window.localStorage.getItem(HIDE_KEY) === '1');
        } catch {
            // Storage can be blocked: the card simply stays visible.
        }
    }, []);

    useEffect(() => {
        if (processing) {
            poll.start();
        } else {
            poll.stop();
        }

        return () => poll.stop();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [processing]);

    useEffect(() => setAgain(false), [listing?.id]);

    const hide = (value: boolean) => {
        setHidden(value);
        try {
            window.localStorage.setItem(HIDE_KEY, value ? '1' : '0');
        } catch {
            // Not remembered: fine.
        }
    };

    if (hidden && listing === null) {
        return (
            <button
                type="button"
                className="self-start text-xs underline"
                onClick={() => hide(false)}
            >
                {t('Start from what you already have')}
            </button>
        );
    }

    return (
        <section
            className="flex flex-col gap-3 rounded-xl border-2 border-primary/40 bg-card p-4"
            aria-live="polite"
        >
            <h2 className="text-base font-semibold">
                {t('Start from what you already have')}
            </h2>

            {listing?.notes && listing.notes.length > 0 && (
                <ul className="list-disc pl-5 text-xs text-amber-800 dark:text-amber-300">
                    {listing.notes.map((note) => (
                        <li key={note}>{note}</li>
                    ))}
                </ul>
            )}

            {listing === null && <SourcesForm onSkip={() => hide(true)} />}

            {processing && (
                <p className="flex items-center gap-2 text-sm">
                    <Spinner />
                    {t(
                        'Reading your material… this can take a minute. You can leave this page and come back.',
                    )}
                </p>
            )}

            {listing?.status === 'failed' && (
                <div className="flex flex-col gap-3 text-sm">
                    <p className="text-destructive">{listing.error}</p>
                    <SourcesForm />
                </div>
            )}

            {listing?.status === 'ready' &&
                (again ? (
                    <SourcesForm onSkip={() => setAgain(false)} />
                ) : (
                    <>
                        <Review key={listing.id} listing={listing} />
                        <button
                            type="button"
                            className="self-start text-xs underline"
                            onClick={() => setAgain(true)}
                        >
                            {t('Start again with other material')}
                        </button>
                    </>
                ))}
        </section>
    );
}
