import { Form, Link } from '@inertiajs/react';
import { Archive, ArchiveRestore, Clock } from 'lucide-react';

import {
    archive,
    unarchive,
} from '@/actions/App/Http/Controllers/HabitController';
import { HabitFormDialog } from '@/components/habits/habit-form-dialog';
import {
    habitTypeLabels,
    recurrenceSummary,
} from '@/components/habits/habit-labels';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { show } from '@/routes/habits';
import type { Habit } from '@/types/models';

/**
 * One habit on the management page: its configuration summary plus the
 * edit and archive/reactivate actions. Archiving keeps the full
 * history — there is no delete.
 */
export function ManageHabitCard({ habit }: { habit: Habit }) {
    const archived = habit.archived_at !== null;

    return (
        <Card size="sm" className={archived ? 'opacity-75' : undefined}>
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
                {archived ? (
                    <Form {...unarchive.form(habit.id)}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                <ArchiveRestore />
                                Reactivar
                            </Button>
                        )}
                    </Form>
                ) : (
                    <>
                        <HabitFormDialog habit={habit} />
                        <Form {...archive.form(habit.id)}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                >
                                    <Archive />
                                    Archivar
                                </Button>
                            )}
                        </Form>
                    </>
                )}
            </CardFooter>
        </Card>
    );
}
