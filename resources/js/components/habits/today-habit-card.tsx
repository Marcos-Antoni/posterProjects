import { Form } from '@inertiajs/react';
import { Check, Clock, Plus } from 'lucide-react';

import { store as storeEntry } from '@/actions/App/Http/Controllers/HabitEntryController';
import { recurrenceSummary } from '@/components/habits/habit-labels';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { Habit } from '@/types/models';

export type HabitTodayProgress = {
    accumulated_amount: number;
    completion_percent: number;
    completed: boolean;
    planned_delta_minutes: number | null;
};

/** A habit as serialized by `HabitController::today()`. */
export type TodayHabit = Omit<
    Habit,
    'user_id' | 'archived_at' | 'created_at' | 'updated_at'
> & {
    today: HabitTodayProgress | null;
    week_recorded_days: number | null;
};

/**
 * One habit on the "Today" view: today's progress plus the quick-log
 * control (a done button for yes/no habits, an amount input for
 * quantitative ones). Weekly-quota habits also show how the current
 * week is going.
 */
export function TodayHabitCard({ habit }: { habit: TodayHabit }) {
    const completed = habit.today?.completed ?? false;

    return (
        <Card className={completed ? 'ring-emerald-500/40' : undefined}>
            <CardHeader>
                <CardTitle className="flex items-center justify-between gap-2">
                    <span className="truncate">{habit.name}</span>
                    {completed && (
                        <span className="flex shrink-0 items-center gap-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                            <Check className="size-4" />
                            Cumplido
                        </span>
                    )}
                </CardTitle>
                <CardDescription className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span>{recurrenceSummary(habit)}</span>
                    {habit.planned_time !== null && (
                        <span className="flex items-center gap-1">
                            <Clock className="size-3.5" />
                            {habit.planned_time.slice(0, 5)}
                        </span>
                    )}
                </CardDescription>
            </CardHeader>

            <CardContent className="flex flex-col gap-3">
                {habit.habit_type === 'quantitative' ? (
                    <QuantitativeProgress habit={habit} />
                ) : (
                    <YesNoProgress habit={habit} />
                )}

                {habit.recurrence_type === 'times_per_week' && (
                    <p className="text-xs text-muted-foreground">
                        Esta semana: {habit.week_recorded_days ?? 0}/
                        {habit.times_per_week} días
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function QuantitativeProgress({ habit }: { habit: TodayHabit }) {
    const accumulated = habit.today?.accumulated_amount ?? 0;
    const percent = habit.today?.completion_percent ?? 0;

    return (
        <>
            <div className="flex items-baseline justify-between gap-2 text-sm">
                <span>
                    {accumulated} / {habit.daily_target} {habit.unit}
                </span>
                <span className="text-muted-foreground">{percent}%</span>
            </div>

            <div className="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full bg-primary transition-all"
                    style={{ width: `${Math.min(100, percent)}%` }}
                />
            </div>

            <Form
                {...storeEntry.form(habit.id)}
                resetOnSuccess
                className="flex flex-col gap-2"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex gap-2">
                            <Input
                                name="amount"
                                type="number"
                                min={1}
                                inputMode="numeric"
                                placeholder="Cantidad"
                                required
                                aria-label={`Cantidad de ${habit.unit ?? ''}`}
                                className="h-9"
                            />
                            <Button
                                type="submit"
                                size="sm"
                                className="h-9 shrink-0"
                                disabled={processing}
                            >
                                <Plus />
                                Registrar
                            </Button>
                        </div>
                        {errors.amount && (
                            <p className="text-sm text-destructive">
                                {errors.amount}
                            </p>
                        )}
                    </>
                )}
            </Form>
        </>
    );
}

function YesNoProgress({ habit }: { habit: TodayHabit }) {
    if (habit.today?.completed) {
        return (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Check className="size-4 text-emerald-600 dark:text-emerald-400" />
                Ya lo hiciste hoy.
            </p>
        );
    }

    return (
        <Form {...storeEntry.form(habit.id)}>
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="outline"
                    className="w-full"
                    disabled={processing}
                >
                    <Check />
                    Marcar como hecho
                </Button>
            )}
        </Form>
    );
}
