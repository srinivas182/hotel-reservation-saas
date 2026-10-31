import { usePage } from '@inertiajs/react';
import { setLocale } from '@/lib/i18n';

/**
 * Keeps the translation locale equal to the one chosen by the server for
 * this page. Called by the top-level layouts before their children render.
 */
export function useLocaleSync(): string {
    const { locale } = usePage().props;
    setLocale(locale);

    return locale;
}
