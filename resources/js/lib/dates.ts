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

/**
 * Absolute, human moment for status lines (mockups 27/28, Nielsen 1):
 * "hoy, 19:02" for today, otherwise "sábado 12 sep 2026, 10:14".
 */
export function formatMoment(value: string, now: Date = new Date()): string {
    const date = new Date(value);
    const time = date.toLocaleTimeString('es', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    if (toIsoDateString(date) === toIsoDateString(now)) {
        return `hoy, ${time}`;
    }

    const day = date
        .toLocaleDateString('es', {
            weekday: 'long',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        })
        .replace(',', '')
        .replace(/ de /g, ' ')
        .replace('.', '');

    return `${day}, ${time}`;
}
