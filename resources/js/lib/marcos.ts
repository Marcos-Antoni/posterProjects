/**
 * Marcos OS display helpers. Days are America/Guatemala (UTC-6, no DST):
 * a stored UTC moment is always shown on its Guatemala day and time.
 */
import { parseIsoDate } from '@/lib/dates';
import type { ItemState } from '@/types/models';

export const MARCOS_TIME_ZONE = 'America/Guatemala';

/** "YYYY-MM-DD" of today in Guatemala. */
export function todayIso(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: MARCOS_TIME_ZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(now);
}

/**
 * A calendar date as the mockups write it: "domingo 11 de octubre" (plus the
 * year when it is not the current one).
 */
export function formatLongDate(iso: string, now: Date = new Date()): string {
    const date = parseIsoDate(iso);
    const sameYear = iso.slice(0, 4) === todayIso(now).slice(0, 4);

    return date
        .toLocaleDateString('es', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            ...(sameYear ? {} : { year: 'numeric' }),
        })
        .replace(',', '');
}

/** "Domingo 11 de octubre" — sentence case for field values. */
export function formatLongDateCapitalized(
    iso: string,
    now: Date = new Date(),
): string {
    const text = formatLongDate(iso, now);

    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** "19:05" in Guatemala. */
export function formatTime(isoMoment: string): string {
    return new Date(isoMoment).toLocaleTimeString('es', {
        timeZone: MARCOS_TIME_ZONE,
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

/**
 * A past moment in the mockups' voice: "hoy 09:20", "ayer 21:10",
 * "el sábado 26 a las 19:05".
 */
export function formatMoment(
    isoMoment: string,
    now: Date = new Date(),
): string {
    const day = todayIso(new Date(isoMoment));
    const today = todayIso(now);
    const yesterday = todayIso(new Date(now.getTime() - 86_400_000));
    const time = formatTime(isoMoment);

    if (day === today) {
        return `hoy ${time}`;
    }

    if (day === yesterday) {
        return `ayer ${time}`;
    }

    const weekday = parseIsoDate(day).toLocaleDateString('es', {
        weekday: 'long',
    });

    return `el ${weekday} ${Number(day.slice(8, 10))} a las ${time}`;
}

/** Lower-case the first letter ("Días con…" → "días con…") for inline use. */
export function lowerFirst(text: string): string {
    return text.charAt(0).toLowerCase() + text.slice(1);
}

/** Numbers without trailing ".0" ("14", "2.5"). */
export function formatNumber(value: number | null): string {
    if (value === null) {
        return '';
    }

    return Number.isInteger(value) ? String(value) : value.toLocaleString('es');
}

export const ITEM_STATE_LABELS: Record<ItemState, string> = {
    locked: 'Bloqueada',
    available: 'Disponible',
    active: 'Activa',
    done: 'Hecho',
    retired: 'Retirada',
};

const COUNT_WORDS = [
    'Ningún',
    'Un',
    'Dos',
    'Tres',
    'Cuatro',
    'Cinco',
    'Seis',
    'Siete',
    'Ocho',
    'Nueve',
    'Diez',
];

/** "Tres objetivos activos." / "Un objetivo activo." */
export function activeObjectivesSentence(count: number): string {
    const word = COUNT_WORDS[count] ?? String(count);

    return count === 1
        ? `${word} objetivo activo.`
        : `${word} objetivos activos.`;
}
