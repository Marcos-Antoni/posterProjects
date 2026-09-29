/**
 * Habit display helpers (screens 18–21). Days are America/Guatemala (UTC-6),
 * already computed by the server; these only put them into words. The copy
 * never counts missed days: a gap is a mark, "hoy toca volver", never a debt.
 */
import { parseIsoDate } from '@/lib/dates';

export type HabitType = 'yes_no' | 'quantitative';
export type RecurrenceType = 'daily' | 'specific_weekdays' | 'times_per_week';
export type StreakState = 'ok' | 'at_risk' | 'restart';

/** A day of the tolerant-streak strip (HabitHistory::marks). */
export type DayMark = {
    date: string;
    /** d done · t only 2 min · r repaired gap · g gap · p today pending · o no opportunity · f future */
    mark: 'd' | 't' | 'r' | 'g' | 'p' | 'o' | 'f';
    closes: boolean;
    closed_run: number | null;
};

export type HabitStreak = {
    current: number;
    best: number;
    state: StreakState;
    closed_run: number | null;
    two_minute_returns: number;
    since: string | null;
};

export type HabitLevel = { current: number; total: number; label: string };

export type LevelSuggestion = {
    direction: 'up' | 'down';
    to_level: number;
    label: string;
    cast: number;
    possible: number;
};

export type HabitSummary = {
    id: number;
    name: string;
    two_minute_version: string;
    identity_statement: string | null;
    habit_type: HabitType;
    unit: string | null;
    target: number;
    daily_target: number | null;
    recurrence_type: RecurrenceType;
    weekdays: number[] | null;
    times_per_week: number | null;
    planned_time: string | null;
    objective: { key: string; title: string; state: string } | null;
    plan: { id: number; title: string } | null;
    level: HabitLevel | null;
    streak: HabitStreak;
    retired_at: string | null;
};

export type Tally = { cast: number; possible: number; two_minute: number };

const WEEKDAY_NAMES: Record<number, string> = {
    1: 'lunes',
    2: 'martes',
    3: 'miércoles',
    4: 'jueves',
    5: 'viernes',
    6: 'sábado',
    7: 'domingo',
};

export const WEEKDAY_INITIALS: Record<number, string> = {
    1: 'Lu',
    2: 'Ma',
    3: 'Mi',
    4: 'Ju',
    5: 'Vi',
    6: 'Sa',
    7: 'Do',
};

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * When the habit is expected: "Todos los días", "Martes a viernes",
 * "Lunes, miércoles y viernes", "3 veces por semana".
 */
export function scheduleLabel(
    habit: Pick<
        HabitSummary,
        'recurrence_type' | 'weekdays' | 'times_per_week'
    >,
): string {
    if (habit.recurrence_type === 'daily') {
        return 'Todos los días';
    }

    if (habit.recurrence_type === 'times_per_week') {
        const times = habit.times_per_week ?? 1;

        return times === 1 ? 'Una vez por semana' : `${times} veces por semana`;
    }

    const days = [...(habit.weekdays ?? [])].sort((a, b) => a - b);

    if (days.length === 7) {
        return 'Todos los días';
    }

    const consecutive =
        days.length >= 3 &&
        days.every((day, index) => index === 0 || day === days[index - 1] + 1);

    if (consecutive) {
        return capitalize(
            `${WEEKDAY_NAMES[days[0]]} a ${WEEKDAY_NAMES[days[days.length - 1]]}`,
        );
    }

    const names = days.map((day) => WEEKDAY_NAMES[day]);
    const text =
        names.length > 1
            ? `${names.slice(0, -1).join(', ')} y ${names[names.length - 1]}`
            : (names[0] ?? '');

    return capitalize(text);
}

/** The unit a streak counts in: days, scheduled "idas", or weeks. */
export function streakUnit(recurrence: RecurrenceType, count: number): string {
    const one = count === 1;

    if (recurrence === 'times_per_week') {
        return one ? 'semana' : 'semanas';
    }

    if (recurrence === 'specific_weekdays') {
        return one ? 'ida' : 'idas';
    }

    return one ? 'día' : 'días';
}

/** "12 días", "1 ida". */
export function streakCount(recurrence: RecurrenceType, count: number): string {
    return `${count} ${streakUnit(recurrence, count)}`;
}

/** "domingo 27 de septiembre" (no year). */
export function longDay(iso: string): string {
    return parseIsoDate(iso)
        .toLocaleDateString('es', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        })
        .replace(',', '');
}

/** "27 sep". */
export function shortDay(iso: string): string {
    return parseIsoDate(iso)
        .toLocaleDateString('es', { day: 'numeric', month: 'short' })
        .replace('.', '');
}

/** "martes 29" for the next scheduled day. */
export function weekdayAndDay(iso: string): string {
    const date = parseIsoDate(iso);

    return `${date.toLocaleDateString('es', { weekday: 'long' })} ${date.getDate()}`;
}

const MARK_TITLES: Record<DayMark['mark'], string> = {
    d: 'hecho',
    t: 'versión de 2 minutos',
    r: 'hueco reparado',
    g: 'hueco',
    p: 'hoy, pendiente',
    o: 'no toca',
    f: 'todavía no',
};

export function markTitle(mark: DayMark['mark']): string {
    return MARK_TITLES[mark];
}

/** The amount of a quantitative habit, "0 de 2 páginas". */
export function amountLabel(
    accumulated: number,
    target: number,
    unit: string | null,
): string {
    return `${accumulated} de ${target}${unit ? ` ${unit}` : ''}`;
}
