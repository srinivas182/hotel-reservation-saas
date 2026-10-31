/**
 * Lightweight translations shared with Laravel.
 *
 * Keys are the English text (as with Laravel's __()), stored in
 * lang/{locale}.json and used by both the server and the frontend.
 * Placeholders use Laravel's syntax: t('Welcome, :name', { name }).
 * A key without a translation falls back to the English text, so dynamic
 * values passed through t() (for example a hotel name) are left unchanged.
 */
const dictionaries = import.meta.glob<Record<string, string>>(
    '../../../lang/*.json',
    {
        eager: true,
        import: 'default',
    },
);

const byLocale: Record<string, Record<string, string>> = Object.fromEntries(
    Object.entries(dictionaries).map(([path, messages]) => [
        path.replace(/^.*\/(\w+)\.json$/, '$1'),
        messages,
    ]),
);

let currentLocale = 'en';

export function setLocale(locale: string): void {
    currentLocale = byLocale[locale] ? locale : 'en';
}

export function getLocale(): string {
    return currentLocale;
}

export function t(
    key: string,
    replacements: Record<string, string | number> = {},
): string {
    let message = byLocale[currentLocale]?.[key] || key;

    // Longest placeholder names first, so :name does not break :names.
    for (const name of Object.keys(replacements).sort(
        (a, b) => b.length - a.length,
    )) {
        message = message.split(`:${name}`).join(String(replacements[name]));
    }

    return message;
}
