import { Link, router } from '@inertiajs/react';

import { BlazeMark, CheckItem } from '@/components/ui/check-item';
import { toast } from '@/components/ui/toast';
import { formatTime } from '@/lib/marcos';
import { today as habitsToday } from '@/routes/habits';
import { store as storeEntry } from '@/routes/habits/entries';

export type NowHabit = {
    id: number;
    name: string;
    habit_type: 'yes_no' | 'quantitative';
    unit: string | null;
    daily_target: number | null;
    accumulated_amount: number;
    completed: boolean;
    done_at: string | null;
    planned_time: string | null;
    two_minute_version: string | null;
};

export type RestingHabit = {
    id: number;
    name: string;
    next_weekday: number | null;
};

const WEEKDAYS = [
    '',
    'lunes',
    'martes',
    'miércoles',
    'jueves',
    'viernes',
    'sábado',
    'domingo',
];

/**
 * "Hábitos de hoy" on the Now screen (mockup 02): the only list the screen
 * has, as one compact row of marks. A yes/no habit is checked right here;
 * a quantitative one shows its progress and opens Hábitos to log amounts.
 * Habits that do not apply today are set apart with the day they come back
 * — a rest, never a miss.
 */
export function NowHabitsRow({
    scheduled,
    resting,
}: {
    scheduled: NowHabit[];
    resting: RestingHabit[];
}) {
    if (scheduled.length === 0 && resting.length === 0) {
        return null;
    }

    return (
        <section className="habits" aria-labelledby="now-habits-h">
            <div className="habits-h">
                <h2 id="now-habits-h">Hábitos de hoy</h2>
                <Link href={habitsToday()} className="small">
                    Ver hábitos
                </Link>
            </div>
            <div className="hrow">
                {scheduled.map((habit) =>
                    habit.habit_type === 'yes_no' ? (
                        <CheckItem
                            key={habit.id}
                            className="hcheck"
                            checked={habit.completed}
                            label={habit.name}
                            description={detail(habit)}
                            onCheckedChange={(checked) => {
                                if (!checked) {
                                    toast(
                                        'Ya quedó hecho hoy. Para corregirlo, andá a Hábitos.',
                                    );

                                    return;
                                }

                                router.post(
                                    storeEntry(habit.id).url,
                                    { amount: 1 },
                                    { preserveScroll: true },
                                );
                            }}
                        />
                    ) : (
                        <Link
                            key={habit.id}
                            href={habitsToday()}
                            className="hcheck flex min-h-14 w-full items-center gap-4 rounded-sm px-3 py-2 text-left hover:bg-sunken"
                        >
                            <BlazeMark checked={habit.completed} />
                            <span className="min-w-0">
                                {habit.name}
                                <span className="block text-sm text-muted-foreground">
                                    {detail(habit)}
                                </span>
                            </span>
                        </Link>
                    ),
                )}
                {resting.map((habit) => (
                    <div className="rest" key={habit.id}>
                        <i aria-hidden="true" />
                        {habit.name} no toca hoy.
                        {habit.next_weekday
                            ? ` Vuelve el ${WEEKDAYS[habit.next_weekday]}.`
                            : ''}
                    </div>
                ))}
            </div>
        </section>
    );
}

function detail(habit: NowHabit): string | undefined {
    if (habit.habit_type === 'quantitative') {
        return `${habit.accumulated_amount} de ${habit.daily_target ?? 0}${habit.unit ? ` ${habit.unit}` : ''}`;
    }

    if (habit.completed && habit.done_at) {
        return `Hecho a las ${formatTime(habit.done_at)}`;
    }

    if (habit.two_minute_version) {
        return `2 min: ${habit.two_minute_version}`;
    }

    return habit.planned_time ? `A las ${habit.planned_time}` : undefined;
}
