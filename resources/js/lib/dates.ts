/**
 * Calendar dates (YYYY-MM-DD) in the browser's own day.
 *
 * `toISOString()` gives the UTC day: east of UTC (India, for example) the
 * day after "2026-11-24" came out as "2026-11-24" again, so a new booking
 * started with check-out on the check-in day.
 */
export function localDate(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** A YYYY-MM-DD date moved by a number of days. */
export function addDays(date: string, days: number): string {
    const value = new Date(`${date}T12:00:00`);
    value.setDate(value.getDate() + days);

    return localDate(value);
}

/** Today in the browser, moved by a number of days. */
export function todayPlus(days = 0): string {
    const value = new Date();
    value.setDate(value.getDate() + days);

    return localDate(value);
}
