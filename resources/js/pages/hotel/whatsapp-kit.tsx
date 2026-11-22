import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    Download,
    ExternalLink,
    FileText,
    ImagePlus,
    QrCode,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageBody, PageHeader } from '@/components/page';
import { t } from '@/lib/i18n';

type Source = {
    id: number;
    code: string;
    kind: string;
    name: string;
    intent: string;
    active: boolean;
    clicks: number;
    chats: number;
    bookings: number;
    short_url: string;
    whatsapp_url: string | null;
    greeting: string;
};

type Props = {
    number: string | null;
    locales: string[];
    intents: { value: string; label: string }[];
    kinds: string[];
    sources: Source[];
    settings: {
        color: string;
        bubble: Record<string, string>;
        online_from: string;
        online_until: string;
    };
    logoUrl: string | null;
    snippet: string;
    google: string | null;
    social: { name: string; url: string; text: string }[];
    monthLabel: string;
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function kindLabel(kind: string): string {
    const labels: Record<string, string> = {
        poster: t('Poster'),
        button: t('Website button'),
        link: t('Link'),
        google: 'Google',
        facebook: 'Facebook',
        instagram: 'Instagram',
        other: t('Other'),
    };

    return labels[kind] ?? kind;
}

function CopyButton({ text, label }: { text: string; label: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => {
                void navigator.clipboard
                    ?.writeText(text)
                    .then(() => setCopied(true));
            }}
        >
            {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
            {copied ? t('Copied') : label}
        </Button>
    );
}

function SourceRow({
    source,
    intents,
    locales,
}: {
    source: Source;
    intents: Props['intents'];
    locales: string[];
}) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        name: source.name,
        intent: source.intent,
        active: source.active,
    });

    return (
        <li className="flex flex-col gap-2 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{source.name}</span>
                <Badge variant="outline">{kindLabel(source.kind)}</Badge>
                <code className="text-xs text-muted-foreground">
                    #{source.code}
                </code>
                {!source.active && (
                    <Badge variant="secondary">{t('hidden')}</Badge>
                )}
                <span className="ml-auto text-muted-foreground">
                    {t(':clicks opened · :chats chats · :bookings bookings', {
                        clicks: source.clicks,
                        chats: source.chats,
                        bookings: source.bookings,
                    })}
                </span>
            </div>
            <p className="text-muted-foreground">
                {t('Pre-typed message:')} “{source.greeting}”
            </p>
            {editing ? (
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/hotel/whatsapp-kit/sources/${source.id}`, {
                            preserveScroll: true,
                            onSuccess: () => setEditing(false),
                        });
                    }}
                >
                    <label className="grid gap-1">
                        <span>{t('Name')}</span>
                        <Input
                            value={form.data.name}
                            maxLength={80}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        <span>{t('Message')}</span>
                        <select
                            className={selectClass}
                            value={form.data.intent}
                            onChange={(e) =>
                                form.setData('intent', e.target.value)
                            }
                        >
                            {intents.map((i) => (
                                <option key={i.value} value={i.value}>
                                    {i.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex items-center gap-2 pb-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={form.data.active}
                            onChange={(e) =>
                                form.setData('active', e.target.checked)
                            }
                        />
                        {t('Active')}
                    </label>
                    <Button type="submit" size="sm" disabled={form.processing}>
                        {t('Save')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setEditing(false)}
                    >
                        {t('Cancel')}
                    </Button>
                    <InputError message={form.errors.name} />
                </form>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <CopyButton
                        text={source.short_url}
                        label={t('Copy the link')}
                    />
                    <Button asChild size="sm" variant="outline">
                        <a href={`/hotel/whatsapp-kit/sources/${source.id}/qr`}>
                            <QrCode aria-hidden />
                            {t('QR code')}
                        </a>
                    </Button>
                    {locales.map((locale) => (
                        <span key={locale} className="flex gap-2">
                            <Button asChild size="sm" variant="outline">
                                <a
                                    href={`/hotel/whatsapp-kit/sources/${source.id}/poster?format=a4&lang=${locale}`}
                                >
                                    <FileText aria-hidden />
                                    {t('Poster A4')}
                                    {locales.length > 1 &&
                                        ` (${locale.toUpperCase()})`}
                                </a>
                            </Button>
                            <Button asChild size="sm" variant="outline">
                                <a
                                    href={`/hotel/whatsapp-kit/sources/${source.id}/poster?format=a5&lang=${locale}`}
                                >
                                    <FileText aria-hidden />
                                    {t('Table tent A5')}
                                    {locales.length > 1 &&
                                        ` (${locale.toUpperCase()})`}
                                </a>
                            </Button>
                        </span>
                    ))}
                    {source.whatsapp_url && (
                        <Button asChild size="sm" variant="ghost">
                            <a
                                href={source.whatsapp_url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink aria-hidden />
                                {t('Try it')}
                            </a>
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setEditing(true)}
                    >
                        {t('Edit')}
                    </Button>
                </div>
            )}
        </li>
    );
}

export default function WhatsAppKit(props: Props) {
    const add = useForm({ kind: 'poster', name: '', intent: 'room' });
    const settings = useForm({
        color: props.settings.color,
        bubble: { ...props.settings.bubble } as Record<string, string>,
        online_from: props.settings.online_from,
        online_until: props.settings.online_until,
    });
    const logo = useForm<{ logo: File | null }>({ logo: null });

    return (
        <>
            <Head title={t('WhatsApp kit')} />
            <PageBody>
                <PageHeader
                    title={t('WhatsApp kit')}
                    description={t(
                        'Bring guests to your WhatsApp assistant: posters with a QR code, a button for your website, links for Google and your social pages. Each one tells you how many chats and bookings it brought.',
                    )}
                />

                {!props.number && (
                    <p
                        role="alert"
                        className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                    >
                        {t(
                            'Your WhatsApp number is not set: add it in the hotel settings so the links open your WhatsApp.',
                        )}{' '}
                        <Link href="/hotel/settings" className="underline">
                            {t('Hotel settings')}
                        </Link>
                    </p>
                )}

                <section
                    aria-labelledby="sources-heading"
                    className="flex flex-col gap-3 rounded-xl border bg-card p-5"
                >
                    <h2 id="sources-heading" className="font-medium">
                        {t('Posters and links')} ·{' '}
                        <span className="font-normal text-muted-foreground">
                            {t('results for :month', {
                                month: props.monthLabel,
                            })}
                        </span>
                    </h2>
                    <ul className="divide-y">
                        {props.sources.map((source) => (
                            <SourceRow
                                key={source.id}
                                source={source}
                                intents={props.intents}
                                locales={props.locales}
                            />
                        ))}
                    </ul>
                    <form
                        className="mt-2 flex flex-wrap items-end gap-2 rounded-xl border bg-card p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            add.post('/hotel/whatsapp-kit/sources', {
                                preserveScroll: true,
                                onSuccess: () => add.reset('name'),
                            });
                        }}
                    >
                        <label className="grid gap-1">
                            <span>{t('Type')}</span>
                            <select
                                className={selectClass}
                                value={add.data.kind}
                                onChange={(e) =>
                                    add.setData('kind', e.target.value)
                                }
                            >
                                {props.kinds.map((k) => (
                                    <option key={k} value={k}>
                                        {kindLabel(k)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid flex-1 gap-1">
                            <span>{t('Where it goes')}</span>
                            <Input
                                value={add.data.name}
                                maxLength={80}
                                placeholder={t('Poster in the rooms')}
                                onChange={(e) =>
                                    add.setData('name', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            <span>{t('Message')}</span>
                            <select
                                className={selectClass}
                                value={add.data.intent}
                                onChange={(e) =>
                                    add.setData('intent', e.target.value)
                                }
                            >
                                {props.intents.map((i) => (
                                    <option key={i.value} value={i.value}>
                                        {i.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={add.processing}
                        >
                            {t('Add')}
                        </Button>
                        <InputError message={add.errors.name} />
                    </form>
                </section>

                <section
                    aria-labelledby="button-heading"
                    className="flex flex-col gap-3 rounded-xl border bg-card p-5"
                >
                    <h2 id="button-heading" className="font-medium">
                        {t('Button for your website')}
                    </h2>
                    <p className="text-muted-foreground">
                        {t(
                            'Paste this line before </body> on your site (WordPress, Wix: “custom code” or “HTML”). It shows “Online” during your hours, in your colour. No cookies, no trackers.',
                        )}
                    </p>
                    <code className="block rounded-md bg-muted p-2 text-xs break-all">
                        {props.snippet}
                    </code>
                    <CopyButton
                        text={props.snippet}
                        label={t('Copy the code')}
                    />
                    <form
                        className="flex flex-col gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            settings.put('/hotel/whatsapp-kit/settings', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="grid gap-1">
                                <span>{t('Colour')}</span>
                                <input
                                    type="color"
                                    className="h-9 w-16 rounded-md border"
                                    value={settings.data.color}
                                    onChange={(e) =>
                                        settings.setData(
                                            'color',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                <span>{t('Online from')}</span>
                                <Input
                                    type="time"
                                    value={settings.data.online_from}
                                    onChange={(e) =>
                                        settings.setData(
                                            'online_from',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                <span>{t('until')}</span>
                                <Input
                                    type="time"
                                    value={settings.data.online_until}
                                    onChange={(e) =>
                                        settings.setData(
                                            'online_until',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                        </div>
                        {props.locales.map((locale) => (
                            <label key={locale} className="grid gap-1">
                                <span>
                                    {t('Bubble text')}
                                    {props.locales.length > 1 &&
                                        ` (${locale.toUpperCase()})`}
                                </span>
                                <Input
                                    maxLength={80}
                                    placeholder={t(
                                        'A room tonight? Write to us',
                                    )}
                                    value={settings.data.bubble[locale] ?? ''}
                                    onChange={(e) =>
                                        settings.setData('bubble', {
                                            ...settings.data.bubble,
                                            [locale]: e.target.value,
                                        })
                                    }
                                />
                            </label>
                        ))}
                        <InputError
                            message={
                                settings.errors.color ??
                                settings.errors.online_from ??
                                settings.errors.online_until
                            }
                        />
                        <Button
                            type="submit"
                            size="sm"
                            className="w-fit"
                            disabled={settings.processing}
                        >
                            {t('Save')}
                        </Button>
                    </form>
                </section>

                <section
                    aria-labelledby="logo-heading"
                    className="flex flex-col gap-2 rounded-xl border bg-card p-5"
                >
                    <h2 id="logo-heading" className="font-medium">
                        {t('Logo on the posters')}
                    </h2>
                    {props.logoUrl && (
                        <img
                            src={props.logoUrl}
                            alt={t('Logo on the posters')}
                            className="max-h-16 w-fit"
                        />
                    )}
                    <form
                        className="flex flex-wrap items-center gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            logo.post('/hotel/whatsapp-kit/logo', {
                                preserveScroll: true,
                                forceFormData: true,
                                onSuccess: () => logo.reset(),
                            });
                        }}
                    >
                        <Input
                            type="file"
                            accept="image/png,image/jpeg"
                            aria-label={t('Choose a logo')}
                            className="w-fit"
                            onChange={(e) =>
                                logo.setData(
                                    'logo',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={!logo.data.logo || logo.processing}
                        >
                            <ImagePlus aria-hidden />
                            {t('Upload')}
                        </Button>
                        {props.logoUrl && (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.delete('/hotel/whatsapp-kit/logo', {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                <Trash2 aria-hidden />
                                {t('Remove')}
                            </Button>
                        )}
                    </form>
                    <InputError message={logo.errors.logo} />
                </section>

                <section
                    aria-labelledby="social-heading"
                    className="flex flex-col gap-3 rounded-xl border bg-card p-5"
                >
                    <h2 id="social-heading" className="font-medium">
                        {t('Google and social pages')}
                    </h2>
                    {props.google && (
                        <div className="flex flex-col gap-1">
                            <p>
                                <span className="font-medium">Google Maps</span>{' '}
                                ·{' '}
                                {t(
                                    'In your Google Business Profile: Edit profile → Contact → add this link as your WhatsApp or booking link.',
                                )}
                            </p>
                            <code className="text-xs break-all">
                                {props.google}
                            </code>
                            <CopyButton
                                text={props.google}
                                label={t('Copy the link')}
                            />
                        </div>
                    )}
                    {props.social.map((s) => (
                        <div key={s.name} className="flex flex-col gap-1">
                            <p>
                                <span className="font-medium">{s.name}</span> ·{' '}
                                {t(
                                    'Put this text in your page’s bio, or the link on the WhatsApp button of your page.',
                                )}
                            </p>
                            <p className="rounded-md bg-muted p-2">{s.text}</p>
                            <div className="flex gap-2">
                                <CopyButton
                                    text={s.text}
                                    label={t('Copy the text')}
                                />
                                <CopyButton
                                    text={s.url}
                                    label={t('Copy the link')}
                                />
                            </div>
                        </div>
                    ))}
                    <p className="flex items-center gap-1 text-xs text-muted-foreground">
                        <Download aria-hidden className="size-3" />
                        {t(
                            'Each link opens WhatsApp with the message ready; the guest only taps Send.',
                        )}
                    </p>
                </section>
            </PageBody>
        </>
    );
}
