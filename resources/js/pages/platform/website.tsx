import { Head, useForm } from '@inertiajs/react';
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
    tagline_fr: string;
    tagline_en: string;
    primary_color: string;
    support_email: string | null;
    support_whatsapp: string | null;
    analytics_domain: string | null;
    analytics_script: string | null;
    logo: string | null;
};
type Props = { pages: Page[]; brand: Brand; canBrand: boolean };

function BrandForm({ brand }: { brand: Brand }) {
    const form = useForm<{
        name: string;
        tagline_fr: string;
        tagline_en: string;
        primary_color: string;
        support_email: string;
        support_whatsapp: string;
        analytics_domain: string;
        analytics_script: string;
        logo: File | null;
    }>({
        name: brand.name,
        tagline_fr: brand.tagline_fr,
        tagline_en: brand.tagline_en,
        primary_color: brand.primary_color,
        support_email: brand.support_email ?? '',
        support_whatsapp: brand.support_whatsapp ?? '',
        analytics_domain: brand.analytics_domain ?? '',
        analytics_script: brand.analytics_script ?? '',
        logo: null,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const field = (
        key:
            | 'name'
            | 'tagline_fr'
            | 'tagline_en'
            | 'support_email'
            | 'support_whatsapp'
            | 'analytics_domain'
            | 'analytics_script',
        label: string,
    ) => (
        <label className="grid gap-0.5 text-xs">
            {label}
            <Input
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
            <InputError message={errors[key]} />
        </label>
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
            className="grid gap-2 rounded-xl border p-3 text-sm sm:grid-cols-3"
        >
            {field('name', t('Product name'))}
            {field('tagline_fr', t('Tagline (FR)'))}
            {field('tagline_en', t('Tagline (EN)'))}
            <label className="grid gap-0.5 text-xs">
                {t('Main colour')}
                <Input
                    type="color"
                    value={form.data.primary_color}
                    onChange={(e) =>
                        form.setData('primary_color', e.target.value)
                    }
                />
            </label>
            {field('support_email', t('Support email'))}
            {field('support_whatsapp', t('Support WhatsApp number'))}
            {field(
                'analytics_domain',
                t('Analytics site (Plausible or Matomo)'),
            )}
            {field('analytics_script', t('Analytics script URL (https)'))}
            <label className="grid gap-0.5 text-xs">
                {t('Logo (SVG, PNG or WebP, 512 KB max)')}
                <input
                    type="file"
                    accept=".svg,.png,.webp"
                    onChange={(e) =>
                        form.setData('logo', e.target.files?.[0] ?? null)
                    }
                />
                <InputError message={errors.logo} />
            </label>
            {brand.logo && (
                <img
                    src={brand.logo}
                    alt={t('Current logo')}
                    className="size-12 rounded-md border object-contain"
                />
            )}
            <div className="sm:col-span-3">
                <Button size="sm" disabled={form.processing}>
                    {t('Save the brand')}
                </Button>
            </div>
        </form>
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
            className="mt-2 grid gap-2 sm:grid-cols-2"
        >
            {(['fr', 'en'] as const).map((lang) => (
                <div key={lang} className="grid gap-2">
                    <Input
                        aria-label={`${t('Title')} ${lang}`}
                        value={form.data.title[lang]}
                        onChange={(e) => set('title', lang, e.target.value)}
                    />
                    <Input
                        aria-label={`${t('Description')} ${lang}`}
                        value={form.data.description[lang]}
                        onChange={(e) =>
                            set('description', lang, e.target.value)
                        }
                    />
                    <textarea
                        aria-label={`${t('Text (Markdown)')} ${lang}`}
                        className="min-h-60 rounded-md border border-input bg-transparent p-2 font-mono text-xs"
                        value={form.data.body[lang]}
                        onChange={(e) => set('body', lang, e.target.value)}
                    />
                </div>
            ))}
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
            <div>
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
                        <details key={p.id} className="rounded-xl border p-2">
                            <summary className="cursor-pointer">
                                {p.title.fr} · /{p.key === 'home' ? '' : p.key}
                            </summary>
                            <a
                                href={p.key === 'home' ? '/' : `/${p.key}`}
                                target="_blank"
                                rel="noreferrer"
                                className="mt-1 inline-block underline"
                            >
                                {t('View')}
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
