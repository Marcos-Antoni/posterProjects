import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';

import { TodayHabitCard } from '@/components/habits/today-habit-card';
import type { TodayHabit } from '@/components/habits/today-habit-card';
import AppLayout from '@/layouts/app-layout';
import { parseIsoDate } from '@/lib/dates';

type HabitsTodayProps = {
    habits: TodayHabit[];
    /** Today in the feature's fixed UTC-6 zone, "YYYY-MM-DD". */
    date: string;
};

export default function HabitsToday({ habits, date }: HabitsTodayProps) {
    const formattedDate = parseIsoDate(date).toLocaleDateString('es-AR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    return (
        <>
            <Head title="Hábitos" />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="font-heading text-2xl font-medium">
                        Hábitos
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Hoy, {formattedDate}.
                    </p>
                </div>

                {habits.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No tenés hábitos programados para hoy.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {habits.map((habit) => (
                            <TodayHabitCard key={habit.id} habit={habit} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

HabitsToday.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
