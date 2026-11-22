import { Head, useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import PlatformSiteController from '@/actions/App/Domains/Site/Http/Controllers/PlatformSiteController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Lang = { fr: string; en: string };
type Page = {
    id: number;
    key: string;
    title: Lang;
    description: Lang;
    body: Lang;
    published: boolean;
};
type Brand = {
    name: string;
    support_hours_fr: string;
    support_hours_en: string;
    tagline_fr: string;
    tagline_en: string;
    primary_color: string;
    support_email: string | null;
    support_whatsapp: string | null;
    analytics_domain: string | null;
    analytics_script: string | null;
    logo: string | null;
    login_photo: string | null;
    default_locale: 'en' | 'fr' | null;
};
type Props = { pages: Page[]; brand: Brand; canBrand: boolean };

function BrandForm({ brand }: { brand: Brand }) {
    const form = useForm<{
        name: string;
        support_hours_fr: string;
        support_hours_en: string;
        tagline_fr: string;
        tagline_en: string;
        primary_color: string;
        support_email: string;
        support_whatsapp: string;
        analytics_domain: string;
        analytics_script: string;
        logo: File | null;
        login_photo: File | null;
        remove_login_photo: boolean;
        default_locale: string;
    }>({
        name: brand.name,
        tagline_fr: brand.tagline_fr,
        tagline_en: brand.tagline_en,
        support_hours_fr: brand.support_hours_fr,
        support_hours_en: brand.support_hours_en,
        primary_color: brand.primary_color,
        support_email: brand.support_email ?? '',
        support_whatsapp: brand.support_whatsapp ?? '',
        analytics_domain: brand.analytics_domain ?? '',
        analytics_script: brand.analytics_script ?? '',
        logo: null,
        login_photo: null,
        remove_login_photo: false,
        default_locale: brand.default_locale ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    type TextKey =
        | 'name'
        | 'support_hours_fr'
        | 'support_hours_en'
        | 'tagline_fr'
        | 'tagline_en'
        | 'support_email'
        | 'support_whatsapp'
        | 'analytics_domain'
        | 'analytics_script';
    const text = (
        key: TextKey,
        label: string,
        extra?: { hint?: string; placeholder?: string; type?: string },
    ) => (
        <Field label={label} hint={extra?.hint} error={errors[key]}>
            <Input
                type={extra?.type ?? 'text'}
                placeholder={extra?.placeholder}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
        </Field>
    );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(PlatformSiteController.updateBrand.url(), {
                    forceFormData: true,
                    preserveScroll: true,
                });
            }}
            className="divide-y rounded-xl border bg-card"
        >
            <FormGroup
                title={t('Identity')}
                description={t(
                    'The name, colour and language visitors see first.',
                )}
            >
                {text('name', t('Product name'))}
                <Field label={t('Main colour')} error={errors.primary_color}>
                    <div className="flex gap-2">
                        <input
                            type="color"
                            aria-label={t('Main colour')}
                            className="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-input bg-card p-1"
                            value={form.data.primary_color}
                            onChange={(e) =>
                                form.setData('primary_color', e.target.value)
                            }
                        />
                        <Input
                            aria-label={t('Main colour')}
                            className="font-mono uppercase"
                            value={form.data.primary_color}
                            onChange={(e) =>
                                form.setData('primary_color', e.target.value)
                            }
                        />
                    </div>
                </Field>
                <Field
                    label={t('Default language')}
                    hint={t(
                        'Where visitors start: the website address, the sign-in pages and new hotels. Everyone can still switch language.',
                    )}
                    error={errors.default_locale}
                >
                    <select
                        className="h-9 w-full"
                        value={form.data.default_locale}
                        onChange={(e) =>
                            form.setData('default_locale', e.target.value)
                        }
                    >
                        <option value="">
                            {t(
                                'Automatic: French website, browser language in the app',
                            )}
                        </option>
                        <option value="en">English</option>
                        <option value="fr">Français</option>
                    </select>
                </Field>
                {text('tagline_fr', t('Tagline (FR)'))}
                {text('tagline_en', t('Tagline (EN)'))}
            </FormGroup>

            <FormGroup
                title={t('Support')}
                description={t(
                    'Shown on the website, in e-mails and in the help centre.',
                )}
            >
                {text('support_email', t('Support email'), {
                    type: 'email',
                    placeholder: 'support@example.com',
                })}
                {text('support_whatsapp', t('Support WhatsApp number'), {
                    placeholder: '+225 07 00 00 00 00',
                })}
                <div className="hidden lg:block" />
                {text('support_hours_fr', t('Support hours (FR)'), {
                    placeholder: 'Lun–Sam, 8 h–20 h',
                })}
                {text('support_hours_en', t('Support hours (EN)'), {
                    placeholder: 'Mon–Sat, 8 am–8 pm',
                })}
            </FormGroup>

            <FormGroup
                title={t('Analytics')}
                description={t(
                    'Optional. Visitor statistics without cookies (Plausible or Matomo).',
                )}
            >
                {text(
                    'analytics_domain',
                    t('Analytics site (Plausible or Matomo)'),
                    {
                        placeholder: 'cnhglobaltech.io',
                    },
                )}
                {text('analytics_script', t('Analytics script URL (https)'), {
                    placeholder: 'https://plausible.io/js/script.js',
                })}
            </FormGroup>

            <FormGroup
                title={t('Images')}
                description={t(
                    'The logo appears in the menu, e-mails and documents.',
                )}
            >
                <Field
                    label={t('Logo')}
                    hint={t('SVG, PNG or WebP, 512 KB max.')}
                    error={errors.logo}
                >
                    <div className="flex items-center gap-3">
                        <span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
                            {brand.logo ? (
                                <img
                                    src={brand.logo}
                                    alt={t('Current logo')}
                                    className="size-12 object-contain"
                                />
                            ) : (
                                <span className="text-[10px] text-muted-foreground">
                                    {t('None')}
                                </span>
                            )}
                        </span>
                        <input
                            type="file"
                            aria-label={t('Logo')}
                            className="min-w-0"
                            accept=".svg,.png,.webp"
                            onChange={(e) =>
                                form.setData(
                                    'logo',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                    </div>
                </Field>
                <Field
                    className="lg:col-span-2"
                    label={t('Sign-in photo')}
                    hint={t(
                        'Shown beside the sign-in and sign-up forms. JPG, PNG or WebP, 3 MB max, at least 800×600. Without one, a colour gradient is shown.',
                    )}
                    error={errors.login_photo}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="flex h-12 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
                            {brand.login_photo ? (
                                <img
                                    src={brand.login_photo}
                                    alt={t('Current sign-in photo')}
                                    className="size-full object-cover"
                                />
                            ) : (
                                <span className="text-[10px] text-muted-foreground">
                                    {t('None')}
                                </span>
                            )}
                        </span>
                        <input
                            type="file"
                            aria-label={t('Sign-in photo')}
                            className="min-w-0"
                            accept=".jpg,.jpeg,.png,.webp"
                            onChange={(e) =>
                                form.setData(
                                    'login_photo',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        {brand.login_photo && (
                            <label className="flex items-center gap-1.5 text-xs">
                                <input
                                    type="checkbox"
                                    checked={form.data.remove_login_photo}
                                    onChange={(e) =>
                                        form.setData(
                                            'remove_login_photo',
                                            e.target.checked,
                                        )
                                    }
                                />
                                {t('Remove')}
                            </label>
                        )}
                    </div>
                </Field>
            </FormGroup>

            <div className="flex items-center justify-end gap-3 bg-muted/40 px-5 py-3">
                {form.recentlySuccessful && (
                    <span className="text-xs text-muted-foreground">
                        {t('Saved')}
                    </span>
                )}
                <Button disabled={form.processing}>
                    {t('Save the brand')}
                </Button>
            </div>
        </form>
    );
}

/** One group of brand settings: a heading on the left, fields on the right. */
function FormGroup({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <fieldset className="grid gap-4 px-5 py-5 lg:grid-cols-[14rem_1fr] lg:gap-8">
            <legend className="sr-only">{title}</legend>
            <div>
                <p className="font-medium">{title}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                    {description}
                </p>
            </div>
            <div className="grid items-start gap-x-4 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                {children}
            </div>
        </fieldset>
    );
}

/** A label, its control, an optional hint and the error, always in that order. */
function Field({
    label,
    hint,
    error,
    className,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={`grid min-w-0 grid-cols-[minmax(0,1fr)] content-start gap-1.5 ${className ?? ''}`}
        >
            <span className="text-xs font-medium">{label}</span>
            {children}
            {hint && (
                <span className="text-xs leading-snug text-muted-foreground">
                    {hint}
                </span>
            )}
            <InputError message={error} />
        </div>
    );
}

function PageForm({ page }: { page: Page }) {
    const form = useForm({
        title: page.title,
        description: page.description,
        body: page.body,
        published: page.published,
    });
    const set = (
        field: 'title' | 'description' | 'body',
        lang: 'fr' | 'en',
        value: string,
    ) => form.setData(field, { ...form.data[field], [lang]: value });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(PlatformSiteController.updatePage.url(page.id), {
                    preserveScroll: true,
                });
            }}
            className="mt-3 grid gap-4 border-t pt-4 sm:grid-cols-2"
        >
            {page.key === 'home' && (
                <p className="text-xs text-muted-foreground sm:col-span-2">
                    {t(
                        'Home page: the title (the part after the last comma is highlighted) and the description open the page. The other sections are part of the design, so the text is not shown here.',
                    )}
                </p>
            )}
            {(['fr', 'en'] as const).map((lang) => (
                <div key={lang} className="grid min-w-0 content-start gap-3">
                    <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {lang === 'fr' ? 'Français' : 'English'}
                    </p>
                    <Field label={t('Title')}>
                        <Input
                            aria-label={`${t('Title')} ${lang}`}
                            value={form.data.title[lang]}
                            onChange={(e) => set('title', lang, e.target.value)}
                        />
                    </Field>
                    <Field label={t('Description')}>
                        <Input
                            aria-label={`${t('Description')} ${lang}`}
                            value={form.data.description[lang]}
                            onChange={(e) =>
                                set('description', lang, e.target.value)
                            }
                        />
                    </Field>
                    {page.key !== 'home' && (
                        <Field label={t('Text (Markdown)')}>
                            <textarea
                                aria-label={`${t('Text (Markdown)')} ${lang}`}
                                className="min-h-60 w-full rounded-md border border-input bg-transparent p-2 font-mono text-xs"
                                value={form.data.body[lang]}
                                onChange={(e) =>
                                    set('body', lang, e.target.value)
                                }
                            />
                        </Field>
                    )}
                </div>
            ))}
            <div className="flex items-center justify-between gap-3 sm:col-span-2">
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.published}
                        onChange={(e) =>
                            form.setData('published', e.target.checked)
                        }
                    />
                    {t('Published')}
                </label>
                <Button size="sm" disabled={form.processing}>
                    {t('Save')}
                </Button>
            </div>
        </form>
    );
}

export default function Website({ pages, brand, canBrand }: Props) {
    return (
        <>
            <Head title={t('Website')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4 text-sm">
                <Heading
                    title={t('Website')}
                    description={t(
                        'The public website: pages in French and English, and the brand used everywhere. Prices come from Billing.',
                    )}
                />
                {canBrand && (
                    <section className="flex flex-col gap-2">
                        <Heading variant="small" title={t('Brand')} />
                        <BrandForm brand={brand} />
                    </section>
                )}
                <section className="flex flex-col gap-2">
                    <Heading variant="small" title={t('Pages')} />
                    {pages.map((p) => (
                        <details
                            key={p.id}
                            className="group rounded-xl border bg-card px-4 py-3"
                        >
                            <summary className="flex cursor-pointer items-center justify-between gap-3">
                                <span className="min-w-0 truncate font-medium">
                                    {p.title.fr}
                                </span>
                                <span className="shrink-0 font-mono text-xs text-muted-foreground">
                                    /{p.key === 'home' ? '' : p.key}
                                </span>
                            </summary>
                            <a
                                href={p.key === 'home' ? '/' : `/${p.key}`}
                                target="_blank"
                                rel="noreferrer"
                                className="mt-2 inline-block text-xs text-brand-ink underline"
                            >
                                {t('View the page')} ↗
                            </a>
                            <PageForm page={p} />
                        </details>
                    ))}
                </section>
            </div>
        </>
    );
}

Website.layout = {
    breadcrumbs: [{ title: 'Website', href: PlatformSiteController.website() }],
};
