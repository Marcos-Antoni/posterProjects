import { Head, Link, router } from '@inertiajs/react';
import type { ReactElement } from 'react';

import { show } from '@/actions/App/Http/Controllers/HabitController';
import { HabitEvolutionChart } from '@/components/habits/habit-evolution-chart';
import type { HabitSeriesPoint } from '@/components/habits/habit-evolution-chart';
import {
    habitTypeLabels,
    recurrenceSummary,
} from '@/components/habits/habit-labels';
import { PlannedVsActualIndicator } from '@/components/habits/planned-vs-actual-indicator';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { today } from '@/routes/habits';
import type { Habit } from '@/types/models';

type HabitMetrics = {
    current_streak: number;
    best_streak: number;
    completion_percent: number;
};

type HabitShowProps = {
    habit: Habit;
    metrics: HabitMetrics;
    series: HabitSeriesPoint[];
    period_days: number;
};

const PERIOD_OPTIONS = [7, 30, 90];

export default function HabitShow({
    habit,
    metrics,
    series,
    period_days: periodDays,
}: HabitShowProps) {
    function selectPeriod(days: number) {
        router.get(
            show.url(habit.id, { query: { days } }),
            {},
            { preserveScroll: true },
        );
    }

    return (
        <>
            <Head title={habit.name} />

            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="font-heading text-2xl font-medium">
                            {habit.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {habitTypeLabels[habit.habit_type]}
                            {habit.habit_type === 'quantitative' &&
                                ` · ${habit.daily_target} ${habit.unit}/día`}
                            {' · '}
                            {recurrenceSummary(habit)}
                        </p>
                    </div>

                    <Link
                        href={today()}
                        className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        Volver a hoy
                    </Link>
                </div>

                <div className="grid grid-cols-3 gap-4">
                    <Card size="sm">
                        <CardHeader>
                            <CardTitle className="text-sm text-muted-foreground">
                                Racha actual
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="font-heading text-2xl font-medium">
                                {metrics.current_streak}
                                <span className="ml-1 text-sm font-normal text-muted-foreground">
                                    días
                                </span>
                            </p>
                        </CardContent>
                    </Card>
                    <Card size="sm">
                        <CardHeader>
                            <CardTitle className="text-sm text-muted-foreground">
                                Mejor racha
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="font-heading text-2xl font-medium">
                                {metrics.best_streak}
                                <span className="ml-1 text-sm font-normal text-muted-foreground">
                                    días
                                </span>
                            </p>
                        </CardContent>
                    </Card>
                    <Card size="sm">
                        <CardHeader>
                            <CardTitle className="text-sm text-muted-foreground">
                                Cumplimiento
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="font-heading text-2xl font-medium">
                                {metrics.completion_percent}
                                <span className="ml-1 text-sm font-normal text-muted-foreground">
                                    %
                                </span>
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex flex-wrap items-center justify-between gap-2">
                            Evolución diaria
                            <span className="flex gap-1">
                                {PERIOD_OPTIONS.map((option) => (
                                    <Button
                                        key={option}
                                        type="button"
                                        size="sm"
                                        variant={
                                            option === periodDays
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => selectPeriod(option)}
                                    >
                                        {option} días
                                    </Button>
                                ))}
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <HabitEvolutionChart series={series} />
                    </CardContent>
                </Card>

                {habit.planned_time !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Planificado vs. real (
                                {habit.planned_time.slice(0, 5)} hs)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <PlannedVsActualIndicator series={series} />
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

HabitShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
