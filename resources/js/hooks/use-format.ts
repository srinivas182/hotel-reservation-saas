import { usePage } from '@inertiajs/react';
import { getLocale } from '@/lib/i18n';

/**
 * Formatting in the user's language and the current hotel's time zone
 * and currency (browser Intl API).
 */
export function useFormat() {
    const { tenancy } = usePage().props;
    const locale = getLocale();
    const timeZone = tenancy.current?.timezone;
    const currency = tenancy.current?.currency ?? 'USD';

    return {
        date: (value: string | null | undefined, fallback = '—') =>
            value
                ? new Intl.DateTimeFormat(locale, {
                      dateStyle: 'medium',
                      timeZone,
                  }).format(new Date(value))
                : fallback,
        dateTime: (value: string | null | undefined, fallback = '—') =>
            value
                ? new Intl.DateTimeFormat(locale, {
                      dateStyle: 'medium',
                      timeStyle: 'short',
                      timeZone,
                  }).format(new Date(value))
                : fallback,
        money: (amount: number, currencyCode: string = currency) =>
            new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: currencyCode,
            }).format(amount),
        number: (value: number) => new Intl.NumberFormat(locale).format(value),
    };
}
