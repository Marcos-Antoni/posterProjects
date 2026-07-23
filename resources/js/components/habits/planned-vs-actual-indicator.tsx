import { Check, Clock } from 'lucide-react';

import type { HabitSeriesPoint } from '@/components/habits/habit-evolution-chart';
import { parseIsoDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

/** Deviation (either way) tolerated before a day counts as off-plan. */
const ON_TIME_TOLERANCE_MINUTES = 15;

function shortDate(value: string): string {
    return parseIsoDate(value).toLocaleDateString('es-AR', {
        day: 'numeric',
        month: 'short',
    });
}

/**
 * Per-day planned-vs-actual strip for habits with a planned time: each
 * recorded day shows whether its first entry landed on time (within a
 * ±15 minute tolerance) or how many minutes it deviated. Icon + text —
 * never color alone. Horizontally scrollable on small screens.
 */
export function PlannedVsActualIndicator({
    series,
}: {
    series: HabitSeriesPoint[];
}) {
    const days = series.filter((point) => point.planned_delta_minutes !== null);

    if (days.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Todavía no hay registros para comparar contra la hora
                planificada.
            </p>
        );
    }

    return (
        <div className="flex gap-2 overflow-x-auto pb-1">
            {days.map((point) => {
                const delta = point.planned_delta_minutes ?? 0;
                const onTime = Math.abs(delta) <= ON_TIME_TOLERANCE_MINUTES;

                return (
                    <div
                        key={point.date}
                        className="flex min-w-16 shrink-0 flex-col items-center gap-1 rounded-lg border border-border px-2 py-1.5"
                    >
                        <span className="text-xs text-muted-foreground">
                            {shortDate(point.date)}
                        </span>
                        <span
                            className={cn(
                                'flex items-center gap-1 text-xs font-medium',
                                onTime
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-amber-600 dark:text-amber-400',
                            )}
                        >
                            {onTime ? (
                                <>
                                    <Check className="size-3.5" />A tiempo
                                </>
                            ) : (
                                <>
                                    <Clock className="size-3.5" />
                                    {delta > 0 ? `+${delta}` : delta} min
                                </>
                            )}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
