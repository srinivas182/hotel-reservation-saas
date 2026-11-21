import { Link, router, usePage } from '@inertiajs/react';
import LocaleController from '@/actions/App/Domains/Identity/Http/Controllers/LocaleController';
import AppLogoIcon from '@/components/app-logo-icon';
import { BrandAccent } from '@/components/brand/brand-accent';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

type Brand = { loginPhoto: string | null; tagline: string | null };

/**
 * Sprint I: the entry screens (sign in, sign up, password, two-step login,
 * invitations). A brand panel on the left (the platform's photo when one is
 * uploaded in Platform → Website, else the aurora gradient) and the form on
 * the right. On a phone, the panel shrinks to a header band.
 */
export default function AuthSplitBrandLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name, brandLogo, brand, locale, locales } = usePage().props as {
        name: string;
        brandLogo?: string | null;
        brand?: Brand;
        locale: string;
        locales: Record<string, string>;
    };
    const photo = brand?.loginPhoto ?? null;

    const Mark = (
        <span className="flex size-10 items-center justify-center overflow-hidden rounded-xl bg-brand text-primary-foreground shadow-lg shadow-black/20">
            {brandLogo ? (
                <img
                    src={brandLogo}
                    alt=""
                    className="size-10 object-contain"
                />
            ) : (
                <AppLogoIcon className="size-6 fill-current" />
            )}
        </span>
    );

    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[1.1fr_1fr]">
            <BrandAccent />
            <aside
                className="relative isolate flex flex-col overflow-hidden px-6 py-6 text-white sm:px-10 lg:px-14 lg:py-12"
                style={{ background: 'var(--hero)' }}
            >
                {photo && (
                    <>
                        <img
                            src={photo}
                            alt=""
                            className="absolute inset-0 -z-20 size-full object-cover"
                        />
                        <div className="absolute inset-0 -z-10 bg-gradient-to-t from-[#0e0f24]/95 via-[#0e0f24]/60 to-[#0e0f24]/30" />
                    </>
                )}
                {!photo && <Aurora />}
                <Link
                    href={home()}
                    className="flex items-center gap-3 font-display text-[15px] font-bold tracking-tight"
                >
                    {Mark}
                    {name}
                </Link>

                <div className="mt-auto hidden lg:block">
                    <p className="text-[11px] font-semibold tracking-[0.18em] text-white/70 uppercase">
                        {t('WhatsApp reservations · West Africa')}
                    </p>
                    <h2 className="mt-4 max-w-xl font-display text-5xl leading-[1.05] font-extrabold tracking-tight">
                        {brand?.tagline ||
                            t('Every guest answered. Every room filled.')}
                    </h2>
                    <p className="mt-5 max-w-md text-base leading-relaxed text-white/75">
                        {t(
                            'Your assistant replies on WhatsApp day and night, in French and English, and your team approves bookings in one tap.',
                        )}
                    </p>

                    <div
                        className="mt-9 w-full max-w-sm rounded-2xl border border-white/15 bg-white/[0.07] p-4 backdrop-blur-md"
                        aria-hidden="true"
                    >
                        <p className="mb-2 flex items-center gap-2 text-xs text-white/75">
                            <span className="size-2 rounded-full bg-emerald-400 shadow-[0_0_0_3px] shadow-emerald-400/25" />
                            WhatsApp
                        </p>
                        <p className="my-1.5 max-w-[85%] rounded-2xl rounded-bl-md bg-white/12 px-3 py-2 text-[13px] leading-snug">
                            {t('Good evening, do you have a room for tonight?')}
                        </p>
                        <p className="my-1.5 ml-auto max-w-[85%] rounded-2xl rounded-br-md bg-brand px-3 py-2 text-[13px] leading-snug font-medium text-primary-foreground">
                            {t(
                                'Yes! An Executive room, breakfast included. Shall I book it for you?',
                            )}
                        </p>
                    </div>

                    <dl className="mt-10 flex gap-10 border-t border-white/15 pt-7">
                        {[
                            ['24/7', t('Replies on WhatsApp')],
                            ['< 1 min', t('Average answer')],
                            ['FR · EN', t('Guest languages')],
                        ].map(([value, label]) => (
                            <div key={label}>
                                <dt className="font-display text-2xl font-extrabold">
                                    {value}
                                </dt>
                                <dd className="text-xs text-white/65">
                                    {label}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>
            </aside>

            <main className="relative flex flex-col px-6 py-8 sm:px-10">
                <nav
                    className="flex justify-end gap-4 text-sm"
                    aria-label={t('Language')}
                >
                    {Object.entries(locales).map(([code, label]) => (
                        <button
                            key={code}
                            type="button"
                            onClick={() =>
                                router.post(
                                    LocaleController.url(),
                                    { locale: code },
                                    { preserveScroll: true },
                                )
                            }
                            aria-current={code === locale ? 'true' : undefined}
                            className={cn(
                                'rounded-md px-1 transition-colors',
                                code === locale
                                    ? 'font-semibold text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </nav>

                <div className="mx-auto flex w-full max-w-md flex-1 animate-enter flex-col justify-center py-10">
                    <p className="text-[11px] font-semibold tracking-[0.18em] text-brand-ink uppercase">
                        {name}
                    </p>
                    <h1 className="mt-3 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">
                        {title}
                    </h1>
                    {description && (
                        <p className="mt-2 text-[15px] text-muted-foreground">
                            {description}
                        </p>
                    )}
                    <div className="mt-8 flex flex-col gap-6">{children}</div>
                </div>

                <p className="text-center text-xs text-muted-foreground">
                    {t('Secure sign-in')} ·{' '}
                    <a href="/legal/terms" className="hover:text-foreground">
                        {t('Terms')}
                    </a>{' '}
                    ·{' '}
                    <a href="/legal/privacy" className="hover:text-foreground">
                        {t('Privacy')}
                    </a>
                </p>
            </main>
        </div>
    );
}

/** The soft arches behind the brand panel when there is no photo. */
function Aurora() {
    return (
        <svg
            className="absolute -right-32 -bottom-40 -z-10 size-[640px] opacity-[0.18]"
            viewBox="0 0 600 600"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            aria-hidden="true"
        >
            {Array.from({ length: 6 }, (_, i) => {
                const r = 240 - i * 40;

                return (
                    <path
                        key={i}
                        d={`M${60 + i * 40} 600 V${300 + i * 20} a${r} ${r} 0 0 1 ${r * 2} 0 V600`}
                    />
                );
            })}
        </svg>
    );
}
