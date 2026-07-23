import type { Habit, HabitType } from '@/types/models';

export const WEEKDAY_SHORT_LABELS: Record<number, string> = {
    1: 'Lun',
    2: 'Mar',
    3: 'Mié',
    4: 'Jue',
    5: 'Vie',
    6: 'Sáb',
    7: 'Dom',
};

export const habitTypeLabels: Record<HabitType, string> = {
    yes_no: 'Sí / No',
    quantitative: 'Cuantitativo',
};

/**
 * A human-readable summary of when the habit is expected, e.g.
 * "Todos los días", "Lun · Mié · Vie", or "3× por semana".
 */
export function recurrenceSummary(
    habit: Pick<Habit, 'recurrence_type' | 'weekdays' | 'times_per_week'>,
): string {
    if (habit.recurrence_type === 'daily') {
        return 'Todos los días';
    }

    if (habit.recurrence_type === 'specific_weekdays') {
        return (habit.weekdays ?? [])
            .map((weekday) => WEEKDAY_SHORT_LABELS[weekday])
            .join(' · ');
    }

    return `${habit.times_per_week}× por semana`;
}
