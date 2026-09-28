import { Head, Link } from '@inertiajs/react';
import type { ReactElement } from 'react';

import { HabitFormDialog } from '@/components/habits/habit-form-dialog';
import { ManageHabitCard } from '@/components/habits/manage-habit-card';
import AppLayout from '@/layouts/app-layout';
import { today } from '@/routes/habits';
import type { Habit } from '@/types/models';

type HabitsIndexProps = {
    habits: Habit[];
};

export default function HabitsIndex({ habits }: HabitsIndexProps) {
    // Retired habits are hidden (retirement spec): they live in Retirados.
    const active = habits;

    return (
        <>
            <Head title="Gestión de hábitos" />

            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="font-heading text-2xl font-medium">
                            Gestión de hábitos
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Creá, editá y retirá tus hábitos.
                        </p>
                    </div>

                    <div className="flex items-center gap-4">
                        <Link
                            href={today()}
                            className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        >
                            Ver hoy
                        </Link>
                        <HabitFormDialog />
                    </div>
                </div>

                {active.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Todavía no tenés hábitos. Creá el primero con el botón
                        de arriba.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {active.map((habit) => (
                            <ManageHabitCard key={habit.id} habit={habit} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

HabitsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
