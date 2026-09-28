import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';

import { HabitFormDialog } from '@/components/habits/habit-form-dialog';
import {
    habitTypeLabels,
    recurrenceSummary,
} from '@/components/habits/habit-labels';
import { RetireButton } from '@/components/marcos/retire-dialog';
import {
    Card,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    retire as habitRetire,
    retireContext as habitRetireContext,
    show,
} from '@/routes/habits';
import type { Habit } from '@/types/models';

/**
 * One habit on the management page: its configuration summary plus the
 * edit and retire actions. Retiring asks for a reason and keeps the full
 * history — there is no delete; restoring happens from Retirados.
 */
export function ManageHabitCard({ habit }: { habit: Habit }) {
    return (
        <Card size="sm">
            <CardHeader>
                <CardTitle className="truncate">
                    <Link
                        href={show(habit.id)}
                        className="underline-offset-4 hover:underline"
                    >
                        {habit.name}
                    </Link>
                </CardTitle>
                <CardDescription className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span>{habitTypeLabels[habit.habit_type]}</span>
                    {habit.habit_type === 'quantitative' && (
                        <span>
                            {habit.daily_target} {habit.unit}/día
                        </span>
                    )}
                    <span>{recurrenceSummary(habit)}</span>
                    {habit.planned_time !== null && (
                        <span className="flex items-center gap-1">
                            <Clock className="size-3.5" />
                            {habit.planned_time.slice(0, 5)}
                        </span>
                    )}
                </CardDescription>
            </CardHeader>

            <CardFooter className="justify-end gap-2">
                <HabitFormDialog habit={habit} />
                <RetireButton
                    className="inline-flex h-8 items-center rounded-md border border-input px-3 text-sm font-medium hover:bg-muted"
                    contextUrl={habitRetireContext(habit.id).url}
                    actionUrl={habitRetire(habit.id).url}
                />
            </CardFooter>
        </Card>
    );
}
