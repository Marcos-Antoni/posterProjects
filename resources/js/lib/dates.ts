/**
 * Formats a `Date` as a "YYYY-MM-DD" string using its LOCAL calendar day
 * (never UTC) — the inverse of `parseIsoDate`.
 */
export function toIsoDateString(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

/**
 * Parses a "YYYY-MM-DD" string into a local `Date` — never via
 * `new Date(string)`, which parses a date-only ISO string as UTC midnight
 * and can shift the displayed day backward in timezones behind UTC.
 */
export function parseIsoDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}
